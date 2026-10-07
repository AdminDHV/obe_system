<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Pi;
use App\Models\Plo;
use App\Models\Student;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use Illuminate\Support\Collection;

/**
 * Roll-up of the per-student sheet for a whole class, for the faculty and academic advisor.
 *
 * Grouping unit is `students.class_group` (lớp sinh hoạt), which the school declares. Programs
 * that have not declared it fall back to grouping by cohort, so the report still works.
 *
 * Two audiences, one table:
 *   - advisor: the student rows (who is behind, on which outcome)
 *   - faculty: the aggregate row (attainment per PLO/PI for the group)
 *
 * "On-track or better" is `StudentProgressService::is_eligible`, i.e. accumulated credits ≥
 * `obe.measurement_min_completion_percent` of the program's required credits. Note this is a
 * fraction of the WHOLE degree, not an expected-credits-by-term curve — a second-year student is
 * structurally "behind" under it. A real progress curve needs planned credits per term, which the
 * schema does not carry.
 */
class ClassOutcomeSummaryService
{
    public function __construct(
        private StudentProgressService $progress,
        private PiCoverageService $coverage,
    ) {}

    /**
     * @param  bool  $onTrackOnly  true ⇒ statistics count only students on track or better
     * @return array{
     *   group_label:string, grouped_by:string, on_track_only:bool,
     *   is_ratio:bool, threshold:float, target_percent:float,
     *   students_total:int, students_counted:int,
     *   plo_codes:array<int,string>, pi_codes:array<int,string>,
     *   students:array<int,array<string,mixed>>,
     *   plo_summary:array<int,array<string,mixed>>, pi_summary:array<int,array<string,mixed>>
     * }
     */
    public function forGroup(
        int $programId,
        ?int $cohortId = null,
        ?string $classGroup = null,
        bool $onTrackOnly = false,
    ): array {
        $students = Student::where('program_id', $programId)
            ->when($cohortId, fn ($q) => $q->where('cohort_id', $cohortId))
            ->when($classGroup, fn ($q) => $q->where('class_group', $classGroup))
            ->orderBy('student_code')
            ->get();

        $plos = Plo::where('program_id', $programId)->orderBy('code')->get();
        $pis = Pi::whereIn('plo_id', $plos->pluck('id'))->orderBy('code')->get();

        $threshold = ObeConfig::ploAttainmentRatioPass();
        $isRatio = true;

        $progressRows = $this->progress->forProgram($programId, $cohortId);

        $ploScores = $this->scoresByStudent(
            StudentPloResult::whereIn('student_id', $students->pluck('id'))
                ->whereIn('plo_id', $plos->pluck('id'))->get(),
            'plo_id',
        );
        $piScores = $this->scoresByStudent(
            StudentPiResult::whereIn('student_id', $students->pluck('id'))
                ->whereIn('pi_id', $pis->pluck('id'))->get(),
            'pi_id',
        );

        // Độ phủ lấy một lần cho cả lớp: mỗi học viên một lượt truy vấn thì bảng 40 người sẽ nổ
        // thành hàng trăm câu lệnh.
        $coverage = $this->coverage->forStudents($programId, $students);

        $rows = [];
        foreach ($students as $student) {
            $tienDo = $progressRows[$student->id] ?? null;
            $onTrack = $tienDo['is_eligible'] ?? true;
            $phu = $coverage[$student->id] ?? null;

            $rows[] = [
                'student_id' => $student->id,
                'student_code' => $student->student_code,
                'full_name' => $student->full_name,
                'class_group' => $student->class_group,
                'credits_earned' => $tienDo['credits_earned'] ?? 0,
                'required_credits' => $tienDo['required_credits'] ?? null,
                'completion_percent' => $tienDo['completion_percent'] ?? null,
                'on_track' => $onTrack,
                'counted' => ! $onTrackOnly || $onTrack,
                'plo' => $this->rowFor($plos, $ploScores[$student->id] ?? [], 'id'),
                'pi' => $this->rowFor($pis, $piScores[$student->id] ?? [], 'id'),
                // pi_code → "1/3": đã đo qua mấy học phần trên tổng số học phần đo PI đó trong
                // lộ trình riêng của học viên (bắt buộc + hướng chuyên ngành họ đã chọn).
                'pi_coverage' => $phu['pis'] ?? [],
                'track' => $phu['chosen_groups'] ?? [],
                'track_pending' => $phu['pending_choice'] ?? false,
            ];
        }

        $counted = array_values(array_filter($rows, fn ($r) => $r['counted']));

        // Phân biệt "gộp cả khóa vì người dùng không lọc lớp" với "trường CHƯA KHAI lớp nào" —
        // hai chuyện khác nhau, ghi nhầm là đổ lỗi cho trường.
        $hasGroups = $classGroup !== null || $this->groupsFor($programId, $cohortId) !== [];

        return [
            'group_label' => $classGroup ?? 'Cả khóa',
            'grouped_by' => $classGroup !== null ? 'class_group' : ($hasGroups ? 'cohort_all_groups' : 'cohort_no_groups'),
            'on_track_only' => $onTrackOnly,
            'is_ratio' => $isRatio,
            'threshold' => $threshold,
            'target_percent' => ObeConfig::targetAttainmentPercent(),
            'students_total' => count($rows),
            'students_counted' => count($counted),
            'plo_codes' => $plos->pluck('code')->all(),
            'pi_codes' => $pis->pluck('code')->all(),
            'coverage_declared' => (bool) (reset($coverage)['declared'] ?? false),
            // Số học viên chưa chọn hướng chuyên ngành — CVHT cần biết để nhắc, vì mẫu số của họ
            // đang thiếu phần chuyên ngành.
            'track_pending_count' => count(array_filter($rows, fn ($r) => $r['track_pending'])),
            'students' => $rows,
            'plo_summary' => $this->summarise($plos, $counted, 'plo', $threshold),
            'pi_summary' => $this->summarise($pis, $counted, 'pi', $threshold),
        ];
    }

    /**
     * Class groups declared for a program (+ cohort). Empty ⇒ the school has not declared any,
     * and the caller should present the cohort as a single group.
     *
     * @return array<int,string>
     */
    public function groupsFor(int $programId, ?int $cohortId = null): array
    {
        return Student::where('program_id', $programId)
            ->when($cohortId, fn ($q) => $q->where('cohort_id', $cohortId))
            ->whereNotNull('class_group')
            ->distinct()
            ->orderBy('class_group')
            ->pluck('class_group')
            ->all();
    }

    /**
     * Per-outcome attainment over the counted students: % of them at or above the pass mark.
     * Students with no result for an outcome are not counted in its denominator — the same rule
     * the cohort report uses.
     *
     * @param  Collection<int,Plo|Pi>  $outcomes
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function summarise(Collection $outcomes, array $rows, string $key, float $threshold): array
    {
        $target = ObeConfig::targetAttainmentPercent();

        return $outcomes->map(function ($outcome) use ($rows, $key, $threshold, $target) {
            $scores = [];
            foreach ($rows as $row) {
                $score = $row[$key][$outcome->code] ?? null;
                if ($score !== null) {
                    $scores[] = (float) $score;
                }
            }

            $measured = count($scores);
            $passed = count(array_filter($scores, fn ($s) => $s >= $threshold));
            $percent = $measured > 0 ? round($passed / $measured * 100, 1) : null;

            return [
                'code' => $outcome->code,
                'students_measured' => $measured,
                'passed' => $passed,
                'attainment_percent' => $percent,
                'avg_score' => $measured > 0 ? round(array_sum($scores) / $measured, 4) : null,
                'met_target' => $percent !== null && $percent >= $target,
            ];
        })->all();
    }

    /**
     * @param  Collection<int,Plo|Pi>  $outcomes
     * @param  array<int,float>  $scores  outcome id → score
     * @return array<string,float|null>  outcome code → score
     */
    private function rowFor(Collection $outcomes, array $scores, string $idKey): array
    {
        $out = [];
        foreach ($outcomes as $outcome) {
            $out[$outcome->code] = $scores[$outcome->{$idKey}] ?? null;
        }

        return $out;
    }

    /**
     * @param  Collection<int,StudentPloResult|StudentPiResult>  $results
     * @return array<int,array<int,float>>  student_id → (outcome id → score)
     */
    private function scoresByStudent(Collection $results, string $foreignKey): array
    {
        $out = [];
        foreach ($results as $result) {
            $out[$result->student_id][$result->{$foreignKey}] = (float) $result->achieved_score;
        }

        return $out;
    }
}
