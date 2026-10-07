<?php

declare(strict_types=1);

namespace App\Services\OBE\Methods;

use App\Models\Clo;
use App\Models\Plo;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Services\OBE\CoursePiScorer;
use App\Services\OBE\ObeConfig;
use App\Services\OBE\ProgramStructureService;
use App\Services\OBE\WeightedAttainmentFormula;
use Illuminate\Support\Str;

/**
 * Weighted attainment method (docs/cach_tinh_CLO_PI_PLO.md):
 *
 *   PI_ratio  = Σ_course(credit × level[I/R/M/A] × course_pi_score) / Σ_course(credit × level × 10)
 *   PLO_ratio = Σ_PI(PI_ratio × pi_weight) / Σ_PI(pi_weight)
 *
 * course_pi_score is the PI score on a 0-10 scale within one course (process 0.4 / final 0.6).
 * Output is an attainment ratio in 0..1.
 */
class WeightedAttainmentMethod implements ObeMeasurementMethod
{
    public function __construct(
        private CoursePiScorer $coursePiScorer,
        private ProgramStructureService $structure,
    ) {}

    public function computeForStudent(Student $student, Program $program): void
    {
        $piRows = [];
        $piDeletes = [];
        $ploRows = [];
        $ploDeletes = [];

        foreach ($this->structure->forProgram($program, $student->cohort_id ? (int) $student->cohort_id : null) as $plo) {
            $piRatios = [];

            foreach ($plo['pis'] as $pi) {
                $ratio = $this->piRatioForStudent($student, $pi);

                if ($ratio !== null) {
                    $piRows[] = [
                        'student_id' => $student->id,
                        'pi_id' => $pi['pi_id'],
                        'achieved_score' => $ratio,
                        'uuid' => (string) Str::uuid(),
                    ];
                    $piRatios[] = [$ratio, $pi['weight']]; // null weight ⇒ treated as equal weight
                } else {
                    $piDeletes[] = $pi['pi_id'];
                }
            }

            $ploRatio = WeightedAttainmentFormula::ploRatio($piRatios);

            if ($ploRatio !== null) {
                $ploRows[] = [
                    'student_id' => $student->id,
                    'plo_id' => $plo['plo_id'],
                    'achieved_score' => $ploRatio,
                    'level' => $this->determineLevel($ploRatio),
                    'uuid' => (string) Str::uuid(),
                ];
            } else {
                $ploDeletes[] = $plo['plo_id'];
            }
        }

        // Duplicates are prevented by the DB unique keys (student, pi) / (student, plo) — without
        // them, parallel workers could insert two rows and inflate the attainment denominator.
        if ($piRows !== []) {
            StudentPiResult::upsert($piRows, ['student_id', 'pi_id'], ['achieved_score']);
        }
        if ($piDeletes !== []) {
            StudentPiResult::where('student_id', $student->id)->whereIn('pi_id', $piDeletes)->delete();
        }
        if ($ploRows !== []) {
            StudentPloResult::upsert($ploRows, ['student_id', 'plo_id'], ['achieved_score', 'level']);
        }
        if ($ploDeletes !== []) {
            StudentPloResult::where('student_id', $student->id)->whereIn('plo_id', $ploDeletes)->delete();
        }
    }

    /**
     * Attainment ratio of one PI, aggregated over the courses the student has scores in.
     * Each course contributes one [credit, level, course_pi_score].
     *
     * @param  array{pi_id:int, weight:float|null, syllabi:array<int, array{syllabus_id:int, credit:float, weight:float, clo_ids:array<int,int>}>}  $pi
     */
    private function piRatioForStudent(Student $student, array $pi): ?float
    {
        $contribs = [];

        foreach ($pi['syllabi'] as $syllabus) {
            // classId null: keep pooling every class of the course, as before.
            $score = $this->coursePiScorer->score($student, $syllabus['syllabus_id'], $syllabus['clo_ids']);
            if ($score === null) {
                continue; // no scores in this course → no contribution
            }
            $contribs[] = [$syllabus['credit'], $syllabus['weight'], $score];
        }

        return WeightedAttainmentFormula::piRatio($contribs);
    }

    /** PLO level (0-4), derived by scaling the ratio to 10 and applying the level thresholds. */
    private function determineLevel(float $ratio): int
    {
        $thresholds = ObeConfig::ploLevelThresholds(); // [8.5,7.0,5.5,4.0] on the 0-10 scale
        $score10 = $ratio * 10.0;
        $level = count($thresholds);
        foreach ($thresholds as $threshold) {
            if ($score10 >= $threshold) {
                return $level;
            }
            $level--;
        }

        return 0;
    }

    public function ploPassThreshold(): float
    {
        return ObeConfig::ploAttainmentRatioPass();
    }

    public function outputsRatio(): bool
    {
        return true;
    }
}
