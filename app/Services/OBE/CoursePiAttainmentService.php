<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\CourseClass;
use App\Models\CourseOutcomeLevel;
use App\Models\CourseSyllabus;
use App\Models\MappingScaleWeight;
use App\Models\Pi;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use Illuminate\Support\Collection;

/**
 * PI attainment WITHIN one taught class, for the lecturer of that class.
 *
 * Three deliberate choices, because the request was ambiguous:
 *
 * 1. Grain is the CLASS, not the syllabus. The audience owns a section
 *    (`course_classes.lecturer_id`), and "how many students sat it" only means something per
 *    section. Scores are therefore computed with the class filter on (CoursePiScorer::score).
 *
 * 2. This is attainment of a PI *inside a course*, not the program PI. The program figure is
 *    Σ_course(credit × level × score) / Σ_course(credit × level × 10) across every course that
 *    measures the PI, so these numbers do NOT add up to it and must not be presented as if
 *    they did. Pass mark is `obe.clo_pass_threshold`, since a course PI score sits on the same
 *    0-10 scale as a CLO score.
 *
 * 3. N is always "students who actually have a score", counted per column — never enrolment.
 *    `course_class_student` is not filled by every grade importer, so it cannot be trusted;
 *    presence of a score can.
 */
class CoursePiAttainmentService
{
    public function __construct(
        private CoursePiScorer $scorer,
        private PiCoverageService $coverage,
    ) {}

    /**
     * @return array{
     *   pass_threshold:float, target_percent:float, is_question_scored:bool,
     *   students_total:int, pis:array<int, array<string,mixed>>
     * }
     */
    public function forClass(CourseClass $class): array
    {
        $class->loadMissing('cohort.program');
        $program = $class->cohort?->program;

        return ObeConfig::forProgram($class->school_id, $program?->level, fn () => $this->forClassInner($class));
    }

    /** @return array<string,mixed> */
    private function forClassInner(CourseClass $class): array
    {
        $passMark = ObeConfig::cloPassThreshold();
        $target = ObeConfig::targetAttainmentPercent();

        // Ở bảng của GIẢNG VIÊN, độ phủ có nghĩa khác với ở phiếu học viên: câu hỏi là "học phần
        // tôi dạy là 1 trong mấy học phần đo PI này", nên mẫu số lấy ở cấp CHƯƠNG TRÌNH.
        $programId = $class->cohort?->program_id;
        $programCoverage = $programId ? $this->coverage->forProgram((int) $programId) : [];

        $syllabusId = (int) $class->syllabus_id;
        $students = $this->studentsWithDataIn($class);
        $questionScored = StudentRubricScore::where('class_id', $class->id)->exists();

        $rows = [];
        foreach ($this->pisMeasuredBy($syllabusId, (int) $class->school_id, $class) as $entry) {
            /** @var Pi $pi */
            $pi = $entry['pi'];
            $cloIds = $entry['clo_ids'];

            $scores = [];
            foreach ($students as $student) {
                $score = $this->scorer->score($student, $syllabusId, $cloIds, (int) $class->id);
                if ($score !== null) {
                    $scores[] = $score;
                }
            }

            $measured = count($scores);
            $passed = count(array_filter($scores, fn ($s) => $s >= $passMark));
            $percent = $measured > 0 ? round($passed / $measured * 100, 1) : null;

            $rows[] = [
                'pi_code' => $pi->code,
                'plo_code' => $pi->plo?->code,
                'pi_description' => $pi->description,
                'mapping_level' => $entry['level'],
                'clo_codes' => $entry['clo_codes'],
                'students_measured' => $measured,
                'passed' => $passed,
                'attainment_percent' => $percent,
                'avg_score' => $measured > 0 ? round(array_sum($scores) / $measured, 2) : null,
                'met_target' => $percent !== null && $percent >= $target,
                // Tổng số học phần của CTĐT có đo PI này — để GV biết học phần mình đóng góp
                // 1 trong bao nhiêu phần vào chỉ số này, không đọc con số ở đây như thể nó là
                // mức đạt PI của cả chương trình.
                'program_courses' => $programCoverage[$pi->code] ?? null,
                'assessments' => $this->assessmentBreakdown($class, $cloIds, $students, $passMark),
            ];
        }

        return [
            'pass_threshold' => $passMark,
            'target_percent' => $target,
            'is_question_scored' => $questionScored,
            'students_total' => $students->count(),
            'pis' => $rows,
            'clos' => $this->cloRows($class, $passMark, $target),
        ];
    }

    /**
     * Mức đạt từng CLO trong lớp — tầng ngay dưới PI, để giảng viên thấy PI hụt là vì CLO nào.
     *
     * Đọc thẳng `student_clo_results` của LỚP này: đó chính là con số engine đã tính cho từng
     * sinh viên, không tính lại. Ngưỡng đạt dùng `obe.clo_pass_threshold` (khai được theo trường),
     * cùng ngưỡng với bảng PI ở trên vì điểm CLO và điểm PI-trong-môn cùng thang 10.
     *
     * N đếm theo "sinh viên CÓ điểm CLO đó", không đếm sĩ số — cùng nguyên tắc với bảng PI.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cloRows(CourseClass $class, float $passMark, float $target): array
    {
        $clos = Clo::where('syllabus_id', $class->syllabus_id)
            ->orderBy('code')
            ->get(['id', 'code', 'description']);

        if ($clos->isEmpty()) {
            return [];
        }

        // CLO nào đo PI nào, ở mức mấy — cho giảng viên nối được hai bảng với nhau.
        $mappings = CloPiMapping::whereIn('clo_id', $clos->pluck('id'))
            ->with('pi:id,code')
            ->get(['clo_id', 'pi_id', 'mapping_level'])
            ->groupBy('clo_id');

        $results = StudentCloResult::where('class_id', $class->id)
            ->get(['clo_id', 'achieved_score'])
            ->groupBy('clo_id');

        $rows = [];
        foreach ($clos as $clo) {
            $scores = $results->get($clo->id, collect())
                ->map(fn ($r) => (float) $r->achieved_score)->all();

            $measured = count($scores);
            $passed = count(array_filter($scores, fn ($s) => $s >= $passMark));
            $percent = $measured > 0 ? round($passed / $measured * 100, 1) : null;
            $forClo = $mappings->get($clo->id, collect());

            $rows[] = [
                'clo_code' => $clo->code,
                'clo_description' => $clo->description,
                'pi_codes' => $forClo->map(fn ($m) => $m->pi?->code)->filter()->unique()->sort()->implode(', '),
                'mapping_level' => (string) ($forClo->first()->mapping_level ?? ''),
                'students_measured' => $measured,
                'passed' => $passed,
                'attainment_percent' => $percent,
                'avg_score' => $measured > 0 ? round(array_sum($scores) / $measured, 2) : null,
                'met_target' => $percent !== null && $percent >= $target,
            ];
        }

        return $rows;
    }

    /**
     * Course-level roll-up across sections: counts are pooled, so the percentage is
     * Σ passed / Σ measured. A student who took the course twice contributes twice — that is
     * what "students who sat it" means at course level.
     *
     * @param  Collection<int,CourseClass>  $classes
     * @return array<int, array<string,mixed>>
     */
    public function rollUp(Collection $classes): array
    {
        $totals = [];

        foreach ($classes as $class) {
            foreach ($this->forClass($class)['pis'] as $row) {
                $key = $row['pi_code'];
                $totals[$key] ??= [
                    'pi_code' => $row['pi_code'],
                    'plo_code' => $row['plo_code'],
                    'students_measured' => 0,
                    'passed' => 0,
                    'score_sum' => 0.0,
                    'classes' => 0,
                ];
                $totals[$key]['students_measured'] += $row['students_measured'];
                $totals[$key]['passed'] += $row['passed'];
                $totals[$key]['score_sum'] += (float) ($row['avg_score'] ?? 0) * $row['students_measured'];
                $totals[$key]['classes']++;
            }
        }

        $target = ObeConfig::targetAttainmentPercent();

        return array_values(array_map(function (array $t) use ($target) {
            $measured = $t['students_measured'];
            $percent = $measured > 0 ? round($t['passed'] / $measured * 100, 1) : null;

            return [
                'pi_code' => $t['pi_code'],
                'plo_code' => $t['plo_code'],
                'classes' => $t['classes'],
                'students_measured' => $measured,
                'passed' => $t['passed'],
                'attainment_percent' => $percent,
                'avg_score' => $measured > 0 ? round($t['score_sum'] / $measured, 2) : null,
                'met_target' => $percent !== null && $percent >= $target,
            ];
        }, $totals));
    }

    /**
     * Per-assessment figures for one PI: how the class did on each process test and on the final.
     *
     * Diagnostic only. For question-scored courses the PI score itself is category-free by design
     * (assessment weights belong to the course mark), so these columns explain the outcome — they
     * are not inputs to it.
     *
     * @param  array<int,int>  $cloIds
     * @param  Collection<int,Student>  $students
     * @return array<int, array<string,mixed>>
     */
    private function assessmentBreakdown(CourseClass $class, array $cloIds, Collection $students, float $passMark): array
    {
        $all = Assessment::where('syllabus_id', $class->syllabus_id)
            ->with(['cloMappings', 'rubric.criteria'])
            ->orderBy('id')
            ->get();
        // Cùng quy tắc với engine: đề cương đủ trọng số (tổng 100%) ⇒ trung bình các câu; dữ liệu cũ ⇒ Σ đạt / Σ tối đa.
        $meanOfQuestions = QuestionOutcomeScorer::blockWeights($all) !== null;
        $assessments = $all->filter(fn (Assessment $a) => $a->mappedCloIds()->intersect($cloIds)->isNotEmpty());

        $out = [];
        foreach ($assessments as $assessment) {
            $scores = $this->assessmentScores($assessment, $class, $cloIds, $students, $meanOfQuestions);
            $measured = count($scores);
            $passed = count(array_filter($scores, fn ($s) => $s >= $passMark));

            $out[] = [
                'assessment_name' => $assessment->name,
                'category' => $assessment->assessment_category === 'final' ? 'Cuối kỳ' : 'Quá trình',
                'weight_percent' => (float) $assessment->weight_percent,
                'students_sat' => $measured,
                'avg_score' => $measured > 0 ? round(array_sum($scores) / $measured, 2) : null,
                'passed' => $passed,
                'attainment_percent' => $measured > 0 ? round($passed / $measured * 100, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * Scores of one assessment for the PI's CLOs, on a 0-10 scale.
     * Question-scored assessments are re-derived from the question rows (Σ achieved / Σ max × 10)
     * restricted to criteria measuring those CLOs; otherwise the recorded grade is used as is.
     *
     * @param  array<int,int>  $cloIds
     * @param  Collection<int,Student>  $students
     * @return array<int,float>
     */
    private function assessmentScores(Assessment $assessment, CourseClass $class, array $cloIds, Collection $students, bool $meanOfQuestions = false): array
    {
        $criteria = ($assessment->rubric?->criteria ?? collect())
            ->filter(fn ($c) => in_array((int) $c->clo_id, $cloIds, true));

        if ($criteria->isNotEmpty()) {
            $rows = StudentRubricScore::where('class_id', $class->id)
                ->whereIn('rubric_criteria_id', $criteria->pluck('id'))
                ->get()
                ->groupBy('student_id');

            $maxByCriteria = $criteria->pluck('max_score', 'id');

            $scores = [];
            foreach ($rows as $studentScores) {
                $achieved = 0.0;
                $max = 0.0;
                $scaled = [];
                foreach ($studentScores as $row) {
                    $rowMax = (float) ($maxByCriteria[$row->rubric_criteria_id] ?? 0);
                    $achieved += (float) $row->score;
                    $max += $rowMax;
                    if ($rowMax > 0) {
                        $scaled[] = (float) $row->score / $rowMax * 10;
                    }
                }
                if ($meanOfQuestions && $scaled !== []) {
                    $scores[] = array_sum($scaled) / count($scaled);
                } elseif (! $meanOfQuestions && $max > 0) {
                    $scores[] = $achieved / $max * 10;
                }
            }

            if ($scores !== []) {
                return $scores;
            }
        }

        return StudentGrade::where('class_id', $class->id)
            ->where('assessment_id', $assessment->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->whereNotNull('score')
            ->pluck('score')
            ->map(fn ($s) => (float) $s)
            ->all();
    }

    /**
     * PIs measured by this course, with the CLOs that measure them and the highest I/R/M/A level.
     *
     * @return array<int, array{pi:Pi, clo_ids:array<int,int>, clo_codes:string, level:string}>
     */
    private function pisMeasuredBy(int $syllabusId, int $schoolId, ?CourseClass $class = null): array
    {
        // Ma trận CTĐT của khóa có môn này ⇒ mức hiện ra là mức CTĐT, đúng mức engine đang dùng làm trọng số.
        $courseId = $class ? (int) CourseSyllabus::whereKey($syllabusId)->value('course_id') : 0;
        $ctdt = ($class && $class->cohort_id) ? (CourseOutcomeLevel::forCohort((int) $class->cohort_id)[$courseId] ?? null) : null;

        $clos = Clo::where('syllabus_id', $syllabusId)->get(['id', 'code']);
        if ($clos->isEmpty()) {
            return [];
        }

        $mappings = CloPiMapping::whereIn('clo_id', $clos->pluck('id'))->get(['pi_id', 'clo_id', 'mapping_level']);
        if ($mappings->isEmpty()) {
            return [];
        }

        $pis = Pi::whereIn('id', $mappings->pluck('pi_id')->unique())
            ->with('plo:id,code')
            ->orderBy('code')
            ->get();

        $codeById = $clos->pluck('code', 'id');
        // Thứ tự mức lấy TỪ CHÍNH bảng trọng số engine đang dùng (`mapping_scale_weights`), không
        // chép cứng: bảng chép cứng cũ để A=4 trong khi engine để A=3, nên khi một PI được đo qua
        // cả CLO mức M lẫn mức A thì màn hình ghi "A" còn phép tính dùng trọng số của M.
        $order = MappingScaleWeight::forSchool($schoolId);

        $out = [];
        foreach ($pis as $pi) {
            $forPi = $mappings->where('pi_id', $pi->id);
            $level = $forPi->pluck('mapping_level')
                ->sortByDesc(fn ($l) => $order[$l] ?? 0)
                ->first();
            if ($ctdt !== null) {
                $level = $ctdt[(int) $pi->id] ?? '—';
            }

            $cloIds = $forPi->pluck('clo_id')->unique()->map(fn ($id) => (int) $id)->values()->all();

            $out[] = [
                'pi' => $pi,
                'clo_ids' => $cloIds,
                'clo_codes' => $forPi->pluck('clo_id')->unique()
                    ->map(fn ($id) => $codeById[$id] ?? '')->filter()->sort()->implode(', '),
                'level' => (string) $level,
            ];
        }

        return $out;
    }

    /**
     * Students with any score in this class — assessment grades or question rows.
     * Enrolment (`course_class_student`) is not used: two of the grade importers never write it.
     *
     * @return Collection<int,Student>
     */
    private function studentsWithDataIn(CourseClass $class): Collection
    {
        $ids = StudentGrade::where('class_id', $class->id)->whereNotNull('score')
            ->distinct()->pluck('student_id')
            ->merge(StudentRubricScore::where('class_id', $class->id)->distinct()->pluck('student_id'))
            ->unique();

        return Student::whereIn('id', $ids)->orderBy('student_code')->get();
    }
}
