<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Pi;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentPiResult;
use App\Services\OBE\Methods\ObeMethodResolver;

/**
 * PI table for a single student with a per-course breakdown showing where each PI came from.
 *
 * Note: PI values are read from `student_pi_results` and never recomputed here — the
 * breakdown only replays the data the engine used (CLO→PI matrix, CLO results, credits).
 */
class StudentPiBreakdownService
{
    public function __construct(
        private ObeMethodResolver $methods,
        private CoursePiScorer $scorer,
        private PiCoverageService $coverage,
        private ProgramStructureService $structure,
    ) {}

    /**
     * @return array{threshold: float, is_ratio: bool, coverage: array<string, mixed>,
     *               pis: array<int, array<string, mixed>>}
     */
    public function forStudent(Student $student): array
    {
        $program = $student->program;

        $method = $program ? $this->methods->for($program) : null;
        $threshold = $method ? $method->ploPassThreshold() : ObeConfig::ploPassThreshold();
        $isRatio = $method ? $method->outputsRatio() : false;

        if (! $program) {
            return [
                'threshold' => $threshold, 'is_ratio' => $isRatio, 'pis' => [],
                'coverage' => ['declared' => false, 'pending_choice' => false, 'chosen_groups' => []],
            ];
        }

        // Độ phủ đi kèm ngay tại đây để MỌI bảng đọc từ service này (phiếu học viên, trang tra cứu
        // công khai, CSV lẻ, CSV hàng loạt) đều có cùng một mẫu số — không thể lệch nhau.
        $coverage = $this->coverage->forStudent($student);

        // `plo.name` is an accessor over `description`, so the full model must be loaded.
        $pis = Pi::whereHas('plo', fn ($q) => $q->where('program_id', $program->id))
            ->with('plo')
            ->orderBy('code')
            ->get();

        $scores = StudentPiResult::where('student_id', $student->id)
            ->pluck('achieved_score', 'pi_id');

        // Điểm CLO của học viên, khoá theo (CLO, LỚP). KHÔNG khoá theo mỗi `clo_id`: học viên học
        // lại thì một CLO có nhiều lớp, khoá theo clo_id sẽ giữ đúng một dòng tuỳ thứ tự DB trả về
        // — có khi là lần rớt, trong khi engine chấm theo lần học gần nhất.
        $cloResults = StudentCloResult::where('student_id', $student->id)
            ->with('clo:id,code,syllabus_id')
            ->get()
            ->groupBy('clo_id');

        // Khung đo lấy từ CHÍNH nguồn engine dùng, không dựng lại.
        $syllabiByPi = $this->structure->syllabiByPi($program, $student->cohort_id ? (int) $student->cohort_id : null);

        $rows = [];
        foreach ($pis as $pi) {
            if (! isset($scores[$pi->id])) {
                continue; // no course measures this PI yet → do not emit an empty row
            }

            $score = (float) $scores[$pi->id];
            $phu = $coverage['pis'][$pi->code] ?? null;

            $rows[] = [
                'pi_code' => $pi->code,
                'pi_description' => $pi->description,
                'plo_code' => $pi->plo?->code,
                'plo_name' => $pi->plo?->name,
                'achieved_score' => round($score, 4),
                'passed' => $score >= $threshold,
                // "1/3": đã đo qua 1 học phần trên 3 học phần đo PI này trong lộ trình của bạn.
                'courses_done' => $phu['done'] ?? null,
                'courses_planned' => $phu['total'] ?? null,
                'courses' => $this->coursesFor($student, $syllabiByPi[$pi->id] ?? [], $cloResults),
            ];
        }

        return [
            'threshold' => $threshold,
            'is_ratio' => $isRatio,
            'coverage' => [
                'declared' => $coverage['declared'],
                'pending_choice' => $coverage['pending_choice'],
                'chosen_groups' => $coverage['chosen_groups'],
            ],
            'pis' => $rows,
        ];
    }

    /**
     * Các học phần đóng góp vào PI này cho một học viên.
     *
     * Duyệt theo KHUNG ĐO của chương trình (`ProgramStructureService`) chứ không theo những CLO
     * học viên tình cờ có điểm — đó là điểm khác then chốt so với bản cũ:
     *
     *   - `clo_ids` truyền cho `CoursePiScorer` phải là TRỌN BỘ CLO của đề cương map vào PI, đúng
     *     như engine truyền. Truyền tập hẹp hơn thì với môn chấm theo bài đánh giá,
     *     `fromAssessmentGrades()` lọc `intersect($cloIds)` sẽ rớt bài và ra điểm khác.
     *   - Học phần `tín chỉ = 0` hoặc `mức = 0` đã bị khung loại sẵn, nên phiếu không còn liệt kê
     *     những môn mà engine không tính.
     *
     * Nhờ vậy cộng tay các dòng in trên phiếu theo Σ(tín chỉ × mức × điểm) / Σ(tín chỉ × mức × 10)
     * ra ĐÚNG ô "Mức đạt" ở đầu dòng.
     *
     * @param  array<int, array<string, mixed>>  $syllabi  khung đo của PI này
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, StudentCloResult>>  $cloResults
     * @return array<int, array<string, mixed>>
     */
    private function coursesFor(Student $student, array $syllabi, $cloResults): array
    {
        $courses = [];

        foreach ($syllabi as $info) {
            $syllabusId = (int) $info['syllabus_id'];

            // Điểm PI trong môn lấy TỪ CHÍNH ENGINE — cùng hàm, cùng tham số.
            $score = $this->scorer->score($student, $syllabusId, $info['clo_ids']);
            if ($score === null) {
                continue; // chưa có điểm ở môn này → engine cũng không tính
            }

            // Chi tiết CLO: chỉ lấy của LẦN HỌC GẦN NHẤT, đúng lần engine đã chấm.
            $latestClass = $this->scorer->latestClassFor($student, $syllabusId);
            $clos = [];
            foreach ($info['clo_ids'] as $cloId) {
                foreach ($cloResults->get($cloId, collect()) as $row) {
                    if ($latestClass !== null && (int) $row->class_id !== $latestClass) {
                        continue;
                    }
                    $clos[] = [
                        'clo_code' => $row->clo?->code,
                        'level' => $info['level'],
                        'score' => round((float) $row->achieved_score, 2),
                    ];
                }
            }

            $courses[] = [
                'course_code' => $info['course_code'],
                'course_name' => $info['course_name'],
                'credits' => (int) $info['credit'],
                'level' => $info['level'],
                'course_score' => round($score, 2),
                // Trọng số của môn trong phép gộp: tín chỉ × mức.
                'weight' => round($info['credit'] * $info['weight'], 2),
                'clos' => $clos,
            ];
        }

        usort($courses, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return $courses;
    }
}
