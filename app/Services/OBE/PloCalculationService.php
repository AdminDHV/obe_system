<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Models\CloPiMapping;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use Illuminate\Support\Str;

class PloCalculationService
{
    /**
     * Compute PI and PLO for one student: student_grades → student_pi_results → student_plo_results.
     *
     * Per docs/cach_tinh_CLO_PI_PLO.md (Model A), PI is recomputed from the raw process/final
     * grades of the assessments measuring it, not averaged from CLO results; I/R/M/A only
     * decides which CLOs measure a PI and does not enter the number.
     */
    public function calculateForStudent(Student $student, Program $program): array
    {
        $plos = Plo::where('program_id', $program->id)->with('pis')->get();

        $piResults = [];
        $ploResults = [];
        $piDeletes = [];
        $ploDeletes = [];

        foreach ($plos as $plo) {
            $piScoresForPlo = [];

            foreach ($plo->pis as $pi) {
                $piScore = $this->calculatePiScore($student, $pi);

                if ($piScore !== null) {
                    $piResults[] = [
                        'student_id' => $student->id,
                        'pi_id' => $pi->id,
                        'achieved_score' => $piScore,
                        'uuid' => (string) Str::uuid(),
                    ];
                    $piScoresForPlo[] = $piScore;
                } else {
                    // No data left → clear the stale PI result.
                    $piDeletes[] = $pi->id;
                }
            }

            // PLO = plain average of its PI scores (PLO not split into PIs ⇒ equals its single internal PI).
            if (count($piScoresForPlo) > 0) {
                $ploScore = array_sum($piScoresForPlo) / count($piScoresForPlo);

                $ploResults[] = [
                    'student_id' => $student->id,
                    'plo_id' => $plo->id,
                    'achieved_score' => $ploScore,
                    'level' => $this->determinePloLevel($ploScore),
                    'uuid' => (string) Str::uuid(),
                ];
            } else {
                $ploDeletes[] = $plo->id;
            }
        }

        // Duplicates are prevented by the DB unique keys; upsert is only a batched write.
        if ($piResults !== []) {
            StudentPiResult::upsert($piResults, ['student_id', 'pi_id'], ['achieved_score']);
        }
        if ($piDeletes !== []) {
            StudentPiResult::where('student_id', $student->id)->whereIn('pi_id', $piDeletes)->delete();
        }
        if ($ploResults !== []) {
            StudentPloResult::upsert($ploResults, ['student_id', 'plo_id'], ['achieved_score', 'level']);
        }
        if ($ploDeletes !== []) {
            StudentPloResult::where('student_id', $student->id)->whereIn('plo_id', $ploDeletes)->delete();
        }

        return ['pi_results' => $piResults, 'plo_results' => $ploResults];
    }

    /**
     * Model A — score one PI from the raw grades of the assessments measuring it:
     * PI ← CLO (clo_pi_mappings) ← Assessment (assessment_clo_mappings) ← StudentGrade.
     */
    private function calculatePiScore(Student $student, Pi $pi): ?float
    {
        $cloIds = CloPiMapping::where('pi_id', $pi->id)->pluck('clo_id')->unique();
        if ($cloIds->isEmpty()) {
            return null;
        }

        // Assessments measuring this PI, via explicit CLO mappings or rubric criteria;
        // each assessment counts once.
        $assessments = Assessment::where(function ($q) use ($cloIds) {
            $q->whereHas('cloMappings', fn($m) => $m->whereIn('clo_id', $cloIds))
                ->orWhereHas('rubric.criteria', fn($r) => $r->whereIn('clo_id', $cloIds));
        })->get()->keyBy('id');
        if ($assessments->isEmpty()) {
            return null;
        }

        // Raw grades on those assessments, across every class the student took.
        $grades = StudentGrade::where('student_id', $student->id)
            ->whereIn('assessment_id', $assessments->keys())
            ->whereNotNull('score')
            ->get();

        $process = [];
        $final = [];
        foreach ($grades as $grade) {
            $assessment = $assessments->get($grade->assessment_id);
            if (!$assessment) {
                continue;
            }
            $score = (float) $grade->score;
            if ($assessment->assessment_category === 'final') {
                $final[] = $score;
            } else {
                $process[] = $score;
            }
        }

        return ObeScoreFormula::weightedFromCategories($process, $final);
    }

    /**
     * PLO level (0-4); thresholds come from ObeConfig (default 8.5/7.0/5.5/4.0).
     */
    private function determinePloLevel(float $score): int
    {
        $thresholds = ObeConfig::ploLevelThresholds(); // [8.5, 7.0, 5.5, 4.0]
        $level = count($thresholds);
        foreach ($thresholds as $threshold) {
            if ($score >= $threshold) {
                return $level;
            }
            $level--;
        }

        return 0;
    }
}
