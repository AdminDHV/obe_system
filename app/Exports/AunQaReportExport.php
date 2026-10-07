<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Cohort;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\Student;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\StudentProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Báo cáo AUN-QA dạng EXCEL nhiều sheet — đủ để hội đồng lần từ TỔNG HỢP xuống DỮ LIỆU THÔ:
 *
 *   [1] Tổng quan · [2] Mức đạt PLO · [3] Mức đạt PI          ← tổng hợp toàn khóa
 *   [4] Ma trận SV × PLO · [5] Ma trận SV × PI                ← từng sinh viên
 *   [6] Kết quả CLO (SV × học phần × CLO)                     ← tầng gốc của phép đo
 *   [7] Điểm tổng kết học phần (theo trọng số bài)            ← ĐIỂM SỐ, tách rời phép đo
 *   [8] Tiến độ tích lũy (SV nào đã học đủ để kết quả có giá trị đại diện)
 *
 * Số liệu tổng hợp lấy thẳng từ ObeAttainmentService để KHỚP bảng on-screen + CSV.
 */
class AunQaReportExport implements WithMultipleSheets
{
    public function __construct(
        private int $programId,
        private ?int $cohortId = null,
    ) {}

    public function sheets(): array
    {
        $svc = app(ObeAttainmentService::class);
        $program = Program::find($this->programId);
        $cohort = $this->cohortId ? Cohort::find($this->cohortId) : null;

        $plo = $svc->ploAttainment($this->programId, $this->cohortId);
        $pi = $svc->piAttainment($this->programId, $this->cohortId);
        $scoreLabel = $plo['is_ratio'] ? 'Tỉ lệ đạt (%)' : 'Thang 10';
        $fmt = fn ($v) => $v === null ? '—' : $v;

        // Engine mặc định trả PI/PLO ở TỈ LỆ 0..1 → xuất ra "%" cho người đọc, kẻo 0.86 bị hiểu
        // nhầm thành 0.86/10 (nhìn như trượt). Engine thang 10 thì giữ nguyên số.
        $score = fn ($v) => $v === null ? '—' : ($plo['is_ratio']
            ? round((float) $v * 100, 1) . '%'
            : round((float) $v, 2));

        // Tiến độ tích lũy — quyết định SV nào được gộp vào thống kê ở [2]/[3].
        $progress = app(StudentProgressService::class)->forProgram($this->programId, $this->cohortId);
        $elig = $plo['eligibility'];

        // [1] Tổng quan
        $overview = new StructureTemplateSheet('Tổng quan', ['Chỉ tiêu', 'Giá trị'], [
            ['Ngành', $program?->name ?? '—'],
            ['Khóa', $cohort?->name ?? 'Tất cả khóa'],
            ['Ngưỡng đạt', $score($plo['threshold'])],
            ['Mục tiêu mức đạt (%)', $plo['target']],
            ['Thang điểm', $scoreLabel],
            ['Có đo gián tiếp (khảo sát)', $plo['has_indirect'] ? 'Có' : 'Không'],
            ['Số tín chỉ yêu cầu (tốt nghiệp)', $elig['required_credits'] ?? 'Chưa khai'],
            ['Ngưỡng tích lũy để gộp thống kê', $elig['required_credits'] === null
                ? 'Không lọc (chưa khai số tín chỉ yêu cầu)'
                : $elig['min_completion_percent'] . '%'],
            ['Tổng số SV trong phạm vi', $elig['students_total']],
            ['SV gộp vào thống kê mức đạt', $elig['students_eligible']],
            ['SV CHƯA đủ tích lũy (tách riêng)', $elig['students_excluded']],
        ]);

        // [2] Mức đạt PLO
        $ploRows = array_map(fn ($r) => [
            $r['plo_code'], $r['plo_description'], $r['students_measured'], $r['passed'],
            $fmt($r['attainment_percent']), $r['target_percent'], \App\Services\OBE\ObeAttainmentService::versusTarget($r['gap']), $r['level'],
            $r['met_target'] ? 'Đạt' : 'Chưa đạt', $score($r['avg_score']),
        ], $plo['plos']);
        $ploSheet = new StructureTemplateSheet(
            'Mức đạt PLO',
            ['Mã PLO', 'Mô tả', 'Số SV đo', 'Số đạt', '% đạt', 'Mục tiêu %', 'So với mục tiêu (%)', 'Mức', 'Kết quả', 'Điểm TB'],
            $ploRows,
        );

        // [3] Mức đạt PI (trước đây không hề hiển thị)
        $piRows = array_map(fn ($r) => [
            $r['pi_code'], $r['plo_code'], $r['pi_description'], $r['students_measured'], $r['passed'],
            $fmt($r['attainment_percent']), $r['level'], $r['met_target'] ? 'Đạt' : 'Chưa đạt', $score($r['avg_score']),
        ], $pi['pis']);
        $piSheet = new StructureTemplateSheet(
            'Mức đạt PI',
            ['Mã PI', 'Thuộc PLO', 'Mô tả', 'Số SV đo', 'Số đạt', '% đạt', 'Mức', 'Kết quả', 'TB'],
            $piRows,
        );

        // [4] Ma trận SV × PLO
        $plos = Plo::where('program_id', $this->programId)->orderBy('code')->get();
        $ploHeadings = ['MSSV', 'Họ và Tên', 'Đủ tích lũy'];
        foreach ($plos as $p) {
            $ploHeadings[] = $p->code;
        }
        $ploMatrix = [];
        $this->students()
            ->with('ploResults')
            ->lazy()
            ->each(function (Student $s) use (&$ploMatrix, $plos, $score, $progress) {
                $row = [$s->student_code, $s->full_name, $this->eligibleLabel($progress, $s->id)];
                foreach ($plos as $p) {
                    $res = $s->ploResults->firstWhere('plo_id', $p->id);
                    $row[] = $res ? $score((float) $res->achieved_score) : 'N/A';
                }
                $ploMatrix[] = $row;
            });
        $ploMatrixSheet = new StructureTemplateSheet('Ma trận SV x PLO', $ploHeadings, $ploMatrix);

        // [5] Ma trận SV × PI — chỉ số thực hiện của TỪNG sinh viên (trước đây chỉ có số tổng hợp).
        $pis = Pi::whereHas('plo', fn ($q) => $q->where('program_id', $this->programId))
            ->orderBy('code')
            ->get();
        $piHeadings = ['MSSV', 'Họ và Tên', 'Đủ tích lũy'];
        foreach ($pis as $p) {
            $piHeadings[] = $p->code;
        }
        $piMatrix = [];
        $this->students()
            ->with('piResults')
            ->lazy()
            ->each(function (Student $s) use (&$piMatrix, $pis, $score, $progress) {
                $row = [$s->student_code, $s->full_name, $this->eligibleLabel($progress, $s->id)];
                foreach ($pis as $p) {
                    $res = $s->piResults->firstWhere('pi_id', $p->id);
                    $row[] = $res ? $score((float) $res->achieved_score) : 'N/A';
                }
                $piMatrix[] = $row;
            });
        $piMatrixSheet = new StructureTemplateSheet('Ma trận SV x PI', $piHeadings, $piMatrix);

        return [
            $overview, $ploSheet, $piSheet, $ploMatrixSheet, $piMatrixSheet,
            $this->cloDetailSheet(),
            $this->courseGradeSheet(),
            $this->progressSheet($progress),
        ];
    }

    /** Nhãn cột "Đủ tích lũy" — chưa khai số TC yêu cầu thì không phán xét. */
    private function eligibleLabel(array $progress, int $studentId): string
    {
        $row = $progress[$studentId] ?? null;
        if ($row === null || $row['completion_percent'] === null) {
            return '—';
        }

        return $row['is_eligible'] ? 'Đủ' : 'CHƯA';
    }

    /**
     * [8] Tiến độ tích lũy — SV nào đã học đủ để kết quả đo mang tính đại diện.
     *
     * Không có sheet này thì một SV mới học 11/16 học phần vẫn hiện đủ 23 PI + 8 PLO y hệt
     * SV học đủ, và bị đếm vào dòng "% đạt" của khóa. Cột "Đủ tích lũy" ở đây khớp với cột
     * cùng tên trong hai ma trận SV × PLO / SV × PI.
     */
    private function progressSheet(array $progress): StructureTemplateSheet
    {
        $rows = array_map(fn ($r) => [
            $r['student_code'], $r['full_name'],
            $r['courses_graded'], $r['courses_passed'],
            $r['credits_attempted'], $r['credits_earned'],
            $r['required_credits'] ?? '—',
            $r['completion_percent'] === null ? '—' : $r['completion_percent'] . '%',
            $r['completion_percent'] === null ? '—' : ($r['is_eligible'] ? 'Đủ' : 'CHƯA'),
        ], array_values($progress));

        return new StructureTemplateSheet(
            'Tiến độ tích lũy',
            ['MSSV', 'Họ và Tên', 'Số môn có điểm', 'Số môn đạt', 'TC đã học', 'TC tích lũy',
                'TC yêu cầu', '% tích lũy', 'Đủ điều kiện đo'],
            $rows,
        );
    }

    /** Sinh viên trong phạm vi báo cáo (ngành + khóa nếu lọc). */
    private function students(): Builder
    {
        return Student::where('program_id', $this->programId)
            ->when($this->cohortId, fn ($q) => $q->where('cohort_id', $this->cohortId))
            ->orderBy('student_code');
    }

    /**
     * [6] Kết quả CLO chi tiết — tầng GỐC của phép đo (SV × học phần × CLO). Hội đồng kiểm định
     * lần ngược từ PLO → PI → CLO đến đây là chạm dữ liệu thô.
     */
    private function cloDetailSheet(): StructureTemplateSheet
    {
        $rows = DB::table('student_clo_results as r')
            ->join('students as s', 'r.student_id', '=', 's.id')
            ->join('clos as cl', 'r.clo_id', '=', 'cl.id')
            ->join('course_syllabus as cs', 'cl.syllabus_id', '=', 'cs.id')
            ->join('courses as c', 'cs.course_id', '=', 'c.id')
            ->where('s.program_id', $this->programId)
            ->when($this->cohortId, fn ($q) => $q->where('s.cohort_id', $this->cohortId))
            ->orderBy('s.student_code')->orderBy('c.code')->orderBy('cl.code')
            ->get([
                's.student_code', 's.full_name', 'c.code as course_code', 'c.name as course_name',
                'cl.code as clo_code', 'r.achieved_score',
            ])
            ->map(fn ($r) => [
                $r->student_code, $r->full_name, $r->course_code, $r->course_name,
                $r->clo_code, round((float) $r->achieved_score, 2),
            ])
            ->all();

        return new StructureTemplateSheet(
            'Kết quả CLO',
            ['MSSV', 'Họ và Tên', 'Mã học phần', 'Tên học phần', 'CLO', 'Điểm CLO (thang 10)'],
            $rows,
        );
    }

    /**
     * [7] Điểm tổng kết học phần = Σ(điểm bài × trọng số) / Σ trọng số.
     *
     * Đây là ĐIỂM SỐ, tính từ trọng số các bài đánh giá (vd chuyên cần 10% + giữa kỳ 40% + cuối kỳ
     * 50%) — TÁCH RỜI với mức đạt CLO/PI/PLO ở các sheet trên (vốn đo từ rubric/câu hỏi).
     */
    private function courseGradeSheet(): StructureTemplateSheet
    {
        $rows = DB::table('student_grades as g')
            ->join('students as s', 'g.student_id', '=', 's.id')
            ->join('assessments as a', 'g.assessment_id', '=', 'a.id')
            ->join('course_classes as cc', 'g.class_id', '=', 'cc.id')
            ->join('course_syllabus as cs', 'cc.syllabus_id', '=', 'cs.id')
            ->join('courses as c', 'cs.course_id', '=', 'c.id')
            ->where('s.program_id', $this->programId)
            ->when($this->cohortId, fn ($q) => $q->where('s.cohort_id', $this->cohortId))
            ->whereNotNull('g.score')
            ->groupBy('s.student_code', 's.full_name', 'cc.class_code', 'c.code', 'c.name', 'c.credits')
            ->orderBy('s.student_code')->orderBy('c.code')
            ->selectRaw('s.student_code, s.full_name, cc.class_code, c.code as course_code, c.name as course_name, c.credits,
                sum(g.score * a.weight_percent) as num, sum(a.weight_percent) as den')
            ->get()
            ->map(fn ($r) => [
                $r->student_code, $r->full_name, $r->class_code, $r->course_code, $r->course_name,
                (int) $r->credits,
                (float) $r->den > 0 ? round((float) $r->num / (float) $r->den, 2) : '—',
            ])
            ->all();

        return new StructureTemplateSheet(
            'Điểm tổng kết học phần',
            ['MSSV', 'Họ và Tên', 'Mã lớp', 'Mã học phần', 'Tên học phần', 'Tín chỉ', 'Điểm tổng kết'],
            $rows,
        );
    }
}
