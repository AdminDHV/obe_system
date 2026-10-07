<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentPloResult;
use App\Models\MeasurementSnapshot;
use App\Services\OBE\ClassOutcomeSummaryService;
use App\Services\OBE\CourseGradeFormula;
use App\Services\OBE\MeasurementSnapshotService;
use App\Services\OBE\StudentLookupService;
use App\Services\OBE\ObeConfig;
use App\Services\OBE\StudentPiBreakdownService;
use App\Services\OBE\StudentProgressService;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    /**
     * Chặn thao tác lên sinh viên của trường khác. `show`/`exportPlo`/`update`/`destroy` bind model
     * theo uuid nên nếu không kiểm ở đây thì biết uuid là xem/sửa được hồ sơ trường bạn (IDOR).
     */
    private function assertSameSchool(Request $request, Student $student): void
    {
        abort_if((int) $student->school_id !== (int) WorkingSchool::id($request), 404);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $validated = $request->validate([
            'student_code' => ['required', 'string', 'max:255', Rule::unique('students', 'student_code')->where('school_id', $sid)],
            'full_name' => 'required|string|max:255',
            // email/gender/phone TÙY CHỌN: sinh viên nhập từ file danh sách của trường chỉ có
            // MSSV + họ tên. Bắt buộc 3 field này ⇒ mở lên sửa là bị ép bịa dữ liệu.
            'email' => 'nullable|email|max:255|unique:students,email',
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $sid)],
            'cohort_id' => ['required', Rule::exists('cohorts', 'id')->where('school_id', $sid)],
            'gender' => 'nullable|string|max:10',
            'phone' => 'nullable|string|max:20',
            // Lớp sinh hoạt (đơn vị CVHT theo dõi) — tuỳ chọn, trống thì báo cáo gộp theo khóa.
            'class_group' => 'nullable|string|max:100',
            'status' => 'required|string|max:255',
        ]);
        $validated['school_id'] = $sid;

        Student::create($validated);

        return redirect()->back()->with('success', 'Đã tạo sinh viên.');
    }

    public function update(Student $student, Request $request): RedirectResponse
    {
        $this->assertSameSchool($request, $student);
        $sid = $student->school_id;
        $validated = $request->validate([
            'student_code' => ['required', 'string', 'max:255', Rule::unique('students', 'student_code')->where('school_id', $sid)->ignore($student->id)],
            'full_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:students,email,' . $student->id,
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $sid)],
            'cohort_id' => ['required', Rule::exists('cohorts', 'id')->where('school_id', $sid)],
            'gender' => 'nullable|string|max:10',
            'phone' => 'nullable|string|max:20',
            'class_group' => 'nullable|string|max:100',
            'status' => 'required|string|max:255',
        ]);

        $student->update($validated);

        return redirect()->back()->with('success', 'Đã cập nhật sinh viên.');
    }

    public function destroy(Request $request, Student $student): RedirectResponse
    {
        $this->assertSameSchool($request, $student);
        $student->delete();

        return redirect()->back()->with('success', 'Student deleted successfully.');
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $sid = WorkingSchool::id($request);

        $students = Student::with(['program', 'cohort'])->where('school_id', $sid);

        if ($user->hasRole('lecturer') && !$user->hasRole('super_admin')) {
            $students->whereHas('program', fn($q) => $q->where('department_id', $user->department_id));
        }

        if ($search = $request->get('q')) {
            $students->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('student_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($programId = $request->get('program_id')) {
            $students->where('program_id', $programId);
        }

        if ($cohortId = $request->get('cohort_id')) {
            $students->where('cohort_id', $cohortId);
        }

        if ($status = $request->get('status')) {
            $students->where('status', $status);
        }

        if ($classGroup = $request->get('class_group')) {
            $students->where('class_group', $classGroup);
        }

        $students = $students->orderBy('full_name')
            ->paginate(15)
            ->through(fn($s) => [
                'uuid' => $s->uuid,
                'student_code' => $s->student_code,
                'full_name' => $s->full_name,
                'email' => $s->email,
                'gender' => $s->gender,
                'status' => $s->status,
                'date_of_birth' => $s->date_of_birth?->format('d/m/Y'),
                'program_id' => $s->program_id,
                'cohort_id' => $s->cohort_id,
                'class_group' => $s->class_group,
                'program' => $s->program ? ['code' => $s->program->code, 'name' => $s->program->name] : null,
                'cohort' => $s->cohort ? ['name' => $s->cohort->name] : null,
            ]);

        $programs = \App\Models\Program::where('school_id', $sid)->select('id', 'code', 'name')
            ->orderBy('name')
            ->get();

        $cohorts = \App\Models\Cohort::where('school_id', $sid)->select('id', 'name')
            ->orderBy('name')
            ->get();

        // Lớp sinh hoạt đã khai (rỗng ⇒ trường chưa gán, báo cáo sẽ gộp theo khóa).
        $classGroups = Student::where('school_id', $sid)
            ->when($request->get('program_id'), fn ($q, $id) => $q->where('program_id', $id))
            ->when($request->get('cohort_id'), fn ($q, $id) => $q->where('cohort_id', $id))
            ->whereNotNull('class_group')
            ->distinct()->orderBy('class_group')->pluck('class_group');

        return Inertia::render('Manager/Students/Index', [
            'students' => $students,
            'programs' => $programs,
            'cohorts' => $cohorts,
            'classGroups' => $classGroups,
            'currentFilters' => $request->only(['q', 'program_id', 'cohort_id', 'class_group', 'status', 'page']),
        ]);
    }

    /**
     * Cấp LẠI mã tra cứu cho 1 sinh viên (khi mất mã, hoặc nghi lộ).
     * Mã cũ mất hiệu lực NGAY — phải phát lại phiếu cho sinh viên.
     */
    public function regenerateLookupCode(
        Request $request,
        Student $student,
        StudentLookupService $lookup,
    ): RedirectResponse {
        $this->assertSameSchool($request, $student);

        $code = $lookup->format($lookup->regenerate($student));

        return redirect()->back()->with(
            'success',
            "Đã cấp mã tra cứu mới cho {$student->student_code}: {$code} — mã cũ không dùng được nữa.",
        );
    }

    /** Phiếu kết quả đầu ra của 1 sinh viên: PLO + PI (kèm môn đóng góp) + tiến độ tích lũy. */
    public function exportPlo(
        Request $request,
        Student $student,
        StudentPiBreakdownService $breakdown,
        StudentProgressService $progress,
    ): StreamedResponse {
        $this->assertSameSchool($request, $student);

        $rows = $this->ploSheetRows($student, $breakdown, $progress);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="PLO_PI_' . $student->student_code . '_' . time() . '.csv"',
        ];

        return response()->stream(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            foreach ($rows as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        }, 200, $headers);
    }

    /**
     * Xuất HÀNG LOẠT phiếu của cả khóa/ngành thành 1 file .zip (mỗi SV 1 CSV) — để khoa phát
     * cho từng sinh viên mà không cần cấp tài khoản. Phạm vi lấy từ trường đang làm việc.
     */
    public function exportPloBulk(
        Request $request,
        StudentPiBreakdownService $breakdown,
        StudentProgressService $progress,
    ): StreamedResponse {
        $schoolId = WorkingSchool::id($request);
        abort_if($schoolId === null, 403, 'Tài khoản chưa được gán trường.');

        $validated = $request->validate([
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $schoolId)],
            'cohort_id' => ['nullable', Rule::exists('cohorts', 'id')->where('school_id', $schoolId)],
        ]);

        $students = Student::where('school_id', $schoolId)
            ->where('program_id', $validated['program_id'])
            ->when($validated['cohort_id'] ?? null, fn ($q, $id) => $q->where('cohort_id', $id))
            ->orderBy('student_code')
            ->get();

        abort_if($students->isEmpty(), 404, 'Không có sinh viên nào trong phạm vi đã chọn.');

        // tempnam() từng gây 500 trên prod (PHP 8.4) khi thư mục không ghi được — dùng storage
        // của ứng dụng, nơi quyền đã được chỉnh trong quy trình deploy.
        $path = storage_path('app/' . uniqid('phieu_sv_', true) . '.zip');
        $zip = new \ZipArchive();
        abort_if($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true, 500, 'Không tạo được file zip.');

        foreach ($students as $student) {
            $csv = chr(0xEF) . chr(0xBB) . chr(0xBF);
            foreach ($this->ploSheetRows($student, $breakdown, $progress) as $row) {
                $csv .= $this->csvLine($row);
            }
            $zip->addFromString('PLO_PI_' . $student->student_code . '.csv', $csv);
        }

        // Mỗi phiếu chứa MÃ TRA CỨU RIÊNG của một người. Gửi nguyên gói này vào nhóm lớp là
        // phát mã của tất cả cho tất cả — cảnh báo ngay trong gói để người phát không lỡ tay.
        $zip->addFromString('DOC-TRUOC-KHI-GUI.txt', implode("\n", [
            'PHIẾU KẾT QUẢ CHUẨN ĐẦU RA — HƯỚNG DẪN PHÁT',
            '',
            'Mỗi file PLO_PI_<MSSV>.csv chứa MÃ TRA CỨU RIÊNG của đúng học viên đó.',
            'Mã này dùng để tự xem kết quả tại: ' . route('lookup.show'),
            '',
            '⚠ CHỈ GỬI TỪNG FILE CHO TỪNG NGƯỜI.',
            '   Gửi nguyên gói .zip này vào nhóm lớp = phát mã của mọi người cho mọi người,',
            '   ai cũng xem được kết quả của bạn cùng lớp.',
            '',
            'Học viên mất mã: khoa vào trang Sinh viên → Chi tiết → "Cấp lại mã tra cứu",',
            'rồi xuất lại phiếu cho riêng người đó.',
        ]));

        $zip->close();

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, 'Phieu_ket_qua_' . $students->count() . 'SV_' . time() . '.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * BẢNG 2 — bảng tổng của cả lớp (gộp nội dung Bảng 1), cho khoa và CVHT.
     * Lọc `on_track=1` ⇒ chỉ gộp thống kê các SV đúng tiến độ trở lên (SV chậm vẫn hiện ở
     * danh sách, chỉ không tính vào mức đạt của lớp).
     */
    public function exportClassSummary(
        Request $request,
        ClassOutcomeSummaryService $summary,
        MeasurementSnapshotService $snapshots,
    ): StreamedResponse {
        $schoolId = WorkingSchool::id($request);
        abort_if($schoolId === null, 403, 'Tài khoản chưa được gán trường.');

        // Xem lại BẢN ĐÃ CHỐT: dựng lại đúng số của thời điểm đó, không tính lại theo hiện tại.
        if ($uuid = $request->query('snapshot')) {
            $snapshot = MeasurementSnapshot::where('uuid', $uuid)->firstOrFail();
            abort_if((int) $snapshot->school_id !== (int) $schoolId, 404);

            return $this->streamClassSummary($snapshots->restore($snapshot));
        }

        $validated = $request->validate([
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $schoolId)],
            'cohort_id' => ['nullable', Rule::exists('cohorts', 'id')->where('school_id', $schoolId)],
            'class_group' => ['nullable', 'string', 'max:100'],
            'on_track' => ['nullable', 'boolean'],
            'snapshot_label' => ['nullable', 'string', 'max:150'],
        ]);

        $data = $summary->forGroup(
            (int) $validated['program_id'],
            isset($validated['cohort_id']) ? (int) $validated['cohort_id'] : null,
            $validated['class_group'] ?? null,
            (bool) ($validated['on_track'] ?? false),
        );

        // CHỐT SỔ mỗi lần xuất: kết quả đo chỉ giữ trạng thái mới nhất, không chốt thì bản vừa gửi
        // cho trường sẽ không bao giờ dựng lại được sau lần "Tính lại" kế tiếp.
        $snapshots->take(
            (int) $validated['program_id'],
            isset($validated['cohort_id']) ? (int) $validated['cohort_id'] : null,
            $validated['class_group'] ?? null,
            (bool) ($validated['on_track'] ?? false),
            $validated['snapshot_label'] ?? '',
            Auth::id(),
        );

        return $this->streamClassSummary($data);
    }

    /**
     * Dựng file CSV bảng tổng lớp. Dùng chung cho số HIỆN TẠI và bản ĐÃ CHỐT nên hai đường không
     * thể vẽ khác nhau.
     *
     * @param  array<string,mixed>  $data
     */
    private function streamClassSummary(array $data): StreamedResponse
    {
        $fmt = fn (?float $v) => $v === null ? '' : ($data['is_ratio'] ? number_format($v * 100, 1) . '%' : number_format($v, 2));
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1) . '%';

        $rows = [
            ['BẢNG TỔNG KẾT QUẢ CHUẨN ĐẦU RA THEO LỚP'],
            ['Phạm vi', $data['group_label']],
            ['Bản chốt', isset($data['snapshot'])
                ? $data['snapshot']['label'] . ' — chốt lúc ' . $data['snapshot']['taken_at']
                : 'Số hiện tại (đã tự chốt sổ khi xuất)'],
            ['Gộp theo', match ($data['grouped_by']) {
                'class_group' => 'Lớp sinh hoạt',
                'cohort_all_groups' => 'Cả khóa (gộp mọi lớp sinh hoạt)',
                default => 'Cả khóa — trường chưa khai lớp sinh hoạt',
            }],
            ['Ngưỡng đạt', $fmt($data['threshold'])],
            ['Mục tiêu mức đạt', number_format($data['target_percent'], 0) . '%'],
            ['Số SV trong phạm vi', $data['students_total']],
            ['Số SV gộp vào thống kê', $data['students_counted']],
            ['Chế độ thống kê', $data['on_track_only'] ? 'Chỉ SV đúng tiến độ trở lên' : 'Toàn bộ SV có kết quả'],
            [],
        ];

        if ($data['students_counted'] === 0) {
            $rows[] = ['⚠ KHÔNG có sinh viên nào được gộp vào thống kê' . ($data['on_track_only']
                ? ' — bộ lọc "đúng tiến độ trở lên" đã loại hết. Các mức đạt bên dưới là KHÔNG CÓ SỐ LIỆU,'
                  . ' không phải "chưa đạt". Kiểm tra lại số tín chỉ yêu cầu của ngành.'
                : '.')];
            $rows[] = [];
        }

        $rows = array_merge($rows, [
            ['[I] MỨC ĐẠT CỦA LỚP THEO PLO'],
            ['Mã PLO', 'Số SV đo', 'Số đạt', '% đạt', 'TB', 'Đạt mục tiêu'],
        ]);

        foreach ($data['plo_summary'] as $row) {
            $rows[] = [
                $row['code'], $row['students_measured'], $row['passed'],
                $pct($row['attainment_percent']), $fmt($row['avg_score']),
                $row['students_measured'] === 0 ? '—' : ($row['met_target'] ? 'Đạt' : 'Chưa đạt'),
            ];
        }

        $rows[] = [];
        $rows[] = ['[II] MỨC ĐẠT CỦA LỚP THEO PI'];
        $rows[] = ['Mã PI', 'Số SV đo', 'Số đạt', '% đạt', 'TB', 'Đạt mục tiêu'];
        foreach ($data['pi_summary'] as $row) {
            $rows[] = [
                $row['code'], $row['students_measured'], $row['passed'],
                $pct($row['attainment_percent']), $fmt($row['avg_score']),
                $row['students_measured'] === 0 ? '—' : ($row['met_target'] ? 'Đạt' : 'Chưa đạt'),
            ];
        }

        $rows[] = [];
        $rows[] = ['[III] TỪNG SINH VIÊN'];
        $rows[] = array_merge(
            ['MSSV', 'Họ và tên', 'Lớp', 'Hướng chuyên ngành', 'TC tích lũy', 'TC yêu cầu',
                '% tích lũy', 'Đúng tiến độ', 'Gộp thống kê'],
            $data['plo_codes'],
            $data['pi_codes'],
        );

        foreach ($data['students'] as $student) {
            $rows[] = array_merge(
                [
                    $student['student_code'], $student['full_name'], $student['class_group'] ?? '',
                    $student['track'] ? implode(', ', $student['track'])
                        : ($student['track_pending'] ? 'CHƯA CHỌN' : ''),
                    $student['credits_earned'], $student['required_credits'] ?? '—',
                    $student['completion_percent'] === null ? '—' : $student['completion_percent'] . '%',
                    $student['on_track'] ? 'Đúng tiến độ' : 'CHẬM',
                    $student['counted'] ? 'Có' : 'Không',
                ],
                array_map($fmt, array_values($student['plo'])),
                array_map($fmt, array_values($student['pi'])),
            );
        }

        // Độ phủ để RIÊNG một mục: nhét "75.4% (1/3)" vào ô của mục III thì cột mức đạt hết là số,
        // Excel không cộng trung bình được nữa.
        if ($data['coverage_declared']) {
            $rows[] = [];
            $rows[] = ['[IV] ĐỘ PHỦ ĐO LƯỜNG — số học phần đã đo / tổng số học phần đo PI đó'];
            $rows[] = ['Tổng đếm theo lộ trình riêng từng SV (khối bắt buộc + hướng chuyên ngành đã chọn),'
                . ' nên hai SV khác hướng có thể khác mẫu số.'];
            if ($data['track_pending_count'] > 0) {
                $rows[] = ['⚠ ' . $data['track_pending_count'] . ' SV chưa chọn hướng chuyên ngành'
                    . ' — mẫu số của họ mới tính phần bắt buộc.'];
            }
            $rows[] = array_merge(['MSSV', 'Họ và tên', 'Hướng chuyên ngành'], $data['pi_codes']);

            foreach ($data['students'] as $student) {
                $rows[] = array_merge(
                    [
                        $student['student_code'], $student['full_name'],
                        $student['track'] ? implode(', ', $student['track'])
                            : ($student['track_pending'] ? 'CHƯA CHỌN' : ''),
                    ],
                    array_map(
                        fn (string $code) => isset($student['pi_coverage'][$code])
                            ? $student['pi_coverage'][$code]['done'] . '/' . $student['pi_coverage'][$code]['total']
                            : '',
                        $data['pi_codes'],
                    ),
                );
            }
        }

        return response()->stream(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            foreach ($rows as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="BangTongLop_' . time() . '.csv"',
        ]);
    }

    /**
     * Nội dung phiếu của 1 sinh viên, dùng chung cho xuất lẻ và xuất hàng loạt.
     *
     * @return array<int, array<int, string|int|float|null>>
     */
    private function ploSheetRows(
        Student $student,
        StudentPiBreakdownService $breakdown,
        StudentProgressService $progress,
    ): array {
        // Mã tra cứu được sinh NGAY KHI xuất phiếu, nên mã luôn có mặt trên tờ giấy học viên nhận.
        $lookup = app(StudentLookupService::class);
        $code = $lookup->format($lookup->ensureCode($student));

        $ploResults = StudentPloResult::where('student_id', $student->id)->with('plo')->get();
        $pi = $breakdown->forStudent($student);
        $tienDo = $progress->forStudent($student);

        // Engine tỉ lệ (0..1) → xuất dạng phần trăm cho người đọc; engine thang 10 → giữ nguyên.
        $fmt = fn (float $v) => $pi['is_ratio'] ? number_format($v * 100, 1) . '%' : number_format($v, 2);
        $verdict = fn (float $v) => $v >= $pi['threshold'] ? 'Đạt' : 'Chưa đạt';

        $rows = [
            ['MSSV', $student->student_code],
            ['Họ và tên', $student->full_name],
            ['Ngưỡng đạt', $fmt((float) $pi['threshold'])],
            ['Mã tra cứu (giữ riêng)', $code],
            ['Xem trực tuyến tại', route('lookup.show') . '  — nhập MSSV + mã tra cứu ở trên'],
            [],
            ['[I] TIẾN ĐỘ TÍCH LŨY'],
            ['Số môn có điểm', $tienDo['courses_graded']],
            ['Số môn đạt (≥ ' . number_format($tienDo['pass_mark'], 1) . ')', $tienDo['courses_passed']],
            ['Tín chỉ đã học', $tienDo['credits_attempted']],
            ['Tín chỉ tích lũy', $tienDo['credits_earned']],
            ['Tín chỉ yêu cầu tốt nghiệp', $tienDo['required_credits'] ?? 'Chưa khai báo'],
            ['% tích lũy', $tienDo['completion_percent'] === null ? '—' : $tienDo['completion_percent'] . '%'],
            ['Đủ điều kiện gộp thống kê khóa', $tienDo['is_eligible'] ? 'Đủ' : 'CHƯA'],
            [],
            ['[II] CHUẨN ĐẦU RA CHƯƠNG TRÌNH (PLO)'],
            ['Mã PLO', 'Tên PLO', 'Mức đạt', 'Kết quả'],
        ];

        foreach ($ploResults as $r) {
            $score = (float) $r->achieved_score;
            $rows[] = [$r->plo->code, $r->plo->name, $fmt($score), $verdict($score)];
        }

        $rows[] = [];
        $rows[] = ['[III] CHỈ SỐ THỰC HIỆN (PI) — kèm các môn đã đóng góp'];
        // Cột "Đã đo / Tổng HP" là mẫu số của mức đạt: 80% khi mới đo 1 trong 3 học phần khác hẳn
        // 80% khi đã đo đủ 3. Tổng đếm theo LỘ TRÌNH RIÊNG của học viên (bắt buộc + hướng đã chọn).
        $rows[] = ['Mã PI', 'Thuộc PLO', 'Mức đạt', 'Kết quả', 'Đã đo / Tổng HP',
            'Môn đóng góp', 'Tín chỉ', 'Mức I/R/M/A', 'Điểm CLO của môn'];

        foreach ($pi['pis'] as $row) {
            $phu = $row['courses_planned'] ? $row['courses_done'] . '/' . $row['courses_planned'] : '';

            foreach ($row['courses'] as $i => $course) {
                $rows[] = [
                    $i === 0 ? $row['pi_code'] : '',
                    $i === 0 ? $row['plo_code'] : '',
                    $i === 0 ? $fmt((float) $row['achieved_score']) : '',
                    $i === 0 ? ($row['passed'] ? 'Đạt' : 'Chưa đạt') : '',
                    $i === 0 ? $phu : '',
                    $course['course_code'] . ' — ' . $course['course_name'],
                    $course['credits'],
                    $course['level'],
                    number_format((float) $course['course_score'], 2),
                ];
            }
        }

        if ($pi['coverage']['chosen_groups'] !== []) {
            array_splice($rows, 2, 0, [['Hướng chuyên ngành', implode(', ', $pi['coverage']['chosen_groups'])]]);
        } elseif ($pi['coverage']['pending_choice']) {
            array_splice($rows, 2, 0, [['Hướng chuyên ngành', 'CHƯA CHỌN — cột "Tổng HP" mới tính phần bắt buộc']]);
        }

        return $rows;
    }

    /**
     * Tên chung của một cụm bài đánh giá, lấy phần đầu giống nhau giữa các tên.
     *
     * "GK1/GK2/GK3" → "GK", "Giữa kỳ 1/Giữa kỳ 2" → "Giữa kỳ". Dùng chính cách đặt tên của trường
     * thay vì áp tên cứng, vì mỗi trường gọi một kiểu. Không tìm được phần chung thì lấy tên bài
     * đầu tiên kèm "…" để người đọc biết còn bài khác bên dưới.
     *
     * @param  array<int, string>  $names
     */
    private static function tenCum(array $names): string
    {
        $dau = $names[0] ?? '';
        $chung = array_shift($names);

        foreach ($names as $name) {
            $i = 0;
            $max = min(mb_strlen($chung), mb_strlen($name));
            while ($i < $max && mb_substr($chung, $i, 1) === mb_substr($name, $i, 1)) {
                $i++;
            }
            $chung = mb_substr($chung, 0, $i);
        }

        // Bỏ chữ số / dấu phân cách còn sót ở đuôi: "Giữa kỳ " → "Giữa kỳ", "GK" giữ nguyên.
        $chung = rtrim($chung, " \t-–—_.:/0123456789");

        return $chung !== '' ? $chung : (mb_substr($dau, 0, 20) . ' …');
    }

    /** 1 dòng CSV thành chuỗi (ZipArchive::addFromString cần chuỗi, không có handle để fputcsv). */
    private function csvLine(array $row): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $row);
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        return $line;
    }

    public function show(
        Request $request,
        Student $student,
        StudentPiBreakdownService $breakdown,
        StudentProgressService $progress,
    ): Response {
        $this->assertSameSchool($request, $student);

        $student->load(['program', 'cohort']);
        $coursePassMark = ObeConfig::coursePassThreshold();
        // Ngưỡng đạt CLO khai được theo trường (`obe.clo_pass_threshold`, mặc định 5). Trước đây
        // chỗ này hardcode 5, nên trường nào chỉnh ngưỡng thì con số "CLO đạt" trên màn hình
        // không khớp với ngưỡng đang dùng để đo.
        $cloPassMark = ObeConfig::cloPassThreshold();

        // Câu giải thích ngắn cho bảng điểm: hệ số nào đang được áp, và vì sao chuyên cần không
        // ảnh hưởng tới mức đạt chuẩn đầu ra. Trường khai hệ số riêng (`obe.process_weight` /
        // `obe.final_weight`) nên KHÔNG viết cứng "40/60" vào giao diện.
        $pw = ObeConfig::processWeight();
        $fw = ObeConfig::finalWeight();
        $tong = $pw + $fw;
        $gradingNote = $tong > 0
            ? sprintf(
                'Đo chuẩn đầu ra: quá trình %s%% · cuối kỳ %s%%. Chuyên cần chỉ vào điểm tổng kết môn, không vào đo chuẩn đầu ra (không gắn CLO nào).',
                rtrim(rtrim(number_format($pw / $tong * 100, 1), '0'), '.'),
                rtrim(rtrim(number_format($fw / $tong * 100, 1), '0'), '.'),
            )
            : null;

        $classes = $student->courseClasses()
            ->with(['syllabus.course', 'syllabus.assessments', 'cohort', 'lecturer'])
            ->get();

        $classIds = $classes->pluck('id')->toArray();

        $grades = StudentGrade::whereIn('class_id', $classIds)
            ->where('student_id', $student->id)
            ->with('assessment')
            ->get()
            ->groupBy('class_id');

        $cloResults = StudentCloResult::where('student_id', $student->id)
            ->whereIn('class_id', $classIds)
            ->with('clo')
            ->get()
            ->groupBy('class_id');

        // Engine mặc định (WeightedAttainment) trả PLO/PI ở thang TỈ LỆ 0..1, không phải điểm /10.
        // Gửi kèm `is_ratio` + `threshold` để UI vẽ đúng thang (trước đây vẽ score/10 → 0.86 thành 8.6%).
        $piBreakdown = $breakdown->forStudent($student);

        $ploResults = StudentPloResult::where('student_id', $student->id)
            ->with('plo')
            ->get()
            ->map(fn($r) => [
                'plo' => ['code' => $r->plo->code, 'name' => $r->plo->name],
                'achieved_score' => round((float) $r->achieved_score, 4),
                'passed' => (float) $r->achieved_score >= $piBreakdown['threshold'],
                'level' => $r->level,
            ]);

        $classesData = $classes->map(function ($class) use ($grades, $cloResults, $coursePassMark, $cloPassMark) {
            $classGrades = $grades->get($class->id, collect());
            $avgScore = $classGrades->whereNotNull('score')->avg('score');

            // Điểm tổng kết môn — quy tắc cụm của Trường, xem CourseGradeFormula.
            $weighted = CourseGradeFormula::fromAssessments(
                $class->syllabus->assessments->map(fn ($assessment) => [
                    'weight' => (float) $assessment->weight_percent,
                    'category' => (string) $assessment->assessment_category,
                    'score' => ($g = $classGrades->firstWhere('assessment_id', $assessment->id))?->score !== null
                        ? (float) $g->score
                        : null,
                ])->all(),
            );
            $weightedAvg = $weighted === null ? null : round($weighted, 2);

            $assessmentGrades = $class->syllabus->assessments->map(function ($assessment) use ($classGrades) {
                $grade = $classGrades->firstWhere('assessment_id', $assessment->id);
                return [
                    'assessment_id' => $assessment->id,
                    'assessment_name' => $assessment->name,
                    'assessment_category' => $assessment->assessment_category,
                    'weight_percent' => $assessment->weight_percent,
                    'score' => $grade?->score !== null ? round((float) $grade->score, 2) : null,
                ];
            });

            // Gộp theo CỤM đúng như CourseGradeFormula gộp để tính: mấy bài giữa kỳ cùng trọng số
            // là MỘT cụm 40%, không phải ba dòng 13.33% rời rạc. Khách nhìn ba dòng 13.33% tưởng
            // hệ thống tính sai, trong khi 13.33 × 3 = 40 và công thức chia theo tổng trọng số nên
            // ra đúng phép trung bình. Gộp ở đây để bảng nhìn thấy và phép tính không thể lệch nhau.
            // Bù trọng số cho bài bỏ trống TRƯỚC khi gộp cụm, để bảng hiển thị và điểm tổng kết
            // dùng chung một bộ trọng số — nếu không, bài trống hiện 0% mà vẫn được tính.
            $assessmentGrades = collect(CourseGradeFormula::resolveWeights(
                $assessmentGrades->map(fn ($a) => $a + ['weight' => (float) $a['weight_percent']])->all(),
            ))->map(fn ($a) => ['weight_percent' => round($a['weight'], 2)] + $a);

            $assessmentGroups = $assessmentGrades
                ->groupBy(fn ($a) => $a['assessment_category'] . '|' . number_format((float) $a['weight_percent'], 2, '.', ''))
                ->map(function ($items) {
                    $scored = $items->whereNotNull('score');
                    $names = $items->pluck('assessment_name')->all();

                    return [
                        'label' => count($names) > 1
                            ? self::tenCum($names) . ' (' . count($names) . ' bài)'
                            : $names[0],
                        // Làm tròn 1 số lẻ: 3 bài × 13.33 cộng lại ra 39.99, hiện nguyên si thì
                        // người dùng lại tưởng hệ thống tính hụt. Cụm đó đúng là 40%.
                        'weight_percent' => round($items->sum(fn ($a) => (float) $a['weight_percent']), 1),
                        'score' => $scored->isEmpty() ? null : round($scored->avg('score'), 2),
                        'items' => $items->values(),
                    ];
                })
                ->sortByDesc('weight_percent')
                ->values();

            $classCloResults = $cloResults->get($class->id, collect());
            $cloDistinct = $classCloResults->unique('clo_id');

            return [
                'uuid' => $class->uuid,
                'class_code' => $class->class_code,
                'course_name' => $class->syllabus?->course?->name ?? '',
                'course_code' => $class->syllabus?->course?->code ?? '',
                'semester' => $class->semester,
                'academic_year' => $class->academic_year,
                'cohort_name' => $class->cohort?->name ?? '',
                'lecturer_name' => $class->lecturer?->full_name ?? '',
                'credits' => $class->syllabus?->course?->credits ?? 0,
                'avg_score' => $avgScore !== null ? round((float) $avgScore, 2) : null,
                'weighted_avg_score' => $weightedAvg,
                // Ngưỡng đạt môn lấy từ `obe.course_pass_threshold` (5.5), KHÔNG hardcode 4.0 —
                // trước đây màn hình báo "Đạt" ở 4.2 trong khi bảng tín chỉ không cộng môn đó.
                'passed' => $weightedAvg !== null && $weightedAvg >= $coursePassMark,
                'clo_achieved_count' => $cloDistinct->where('achieved_score', '>=', $cloPassMark)->count(),
                'clo_total' => $cloDistinct->count(),
                'grades' => $assessmentGrades,
                'grade_groups' => $assessmentGroups,
                'clo_results' => $classCloResults->map(fn($r) => [
                    'clo_code' => $r->clo->code,
                    'clo_description' => $r->clo->description,
                    'achieved_score' => round((float) $r->achieved_score, 2),
                ]),
            ];
        });

        $allCloResults = StudentCloResult::where('student_id', $student->id)
            ->whereIn('class_id', $classIds)
            ->get();

        // Credit-weighted GPA
        $totalWeightedCredits = 0;
        $totalWeightedScore = 0;
        foreach ($classesData as $classData) {
            $credits = $classData['credits'];
            $score = $classData['weighted_avg_score'];
            if ($score !== null && $credits > 0) {
                $totalWeightedCredits += $credits;
                $totalWeightedScore += $score * $credits;
            }
        }
        $gpa = $totalWeightedCredits > 0 ? round($totalWeightedScore / $totalWeightedCredits, 2) : null;

        // NGUỒN DUY NHẤT cho tín chỉ tích lũy: cùng service với báo cáo khóa và bộ lọc "đủ điều
        // kiện gộp thống kê". Trước đây màn hình tự cộng tín chỉ của MỌI môn có điểm (kể cả rớt)
        // nên lệch với báo cáo.
        $tienDo = $progress->forStudent($student);

        $stats = [
            'total_classes' => $classes->count(),
            'avg_score' => $classesData->pluck('avg_score')->filter()->avg() !== null
                ? round($classesData->pluck('avg_score')->filter()->avg(), 2) : null,
            'weighted_gpa' => $gpa,
            // Tín chỉ ĐÃ HỌC (mọi môn có điểm) vs TÍCH LŨY (chỉ môn đạt) — hai con số khác nhau,
            // hiển thị tách bạch để không ai hiểu nhầm như trước.
            'total_credits' => $tienDo['credits_attempted'],
            'credits_earned' => $tienDo['credits_earned'],
            'passed_count' => $classesData->where('passed', true)->count(),
            'clo_achieved' => $allCloResults->where('achieved_score', '>=', $cloPassMark)->count(),
            'clo_total' => $allCloResults->count(),
            // Đếm theo NGƯỠNG của method đang dùng (0.5 với thang tỉ lệ), không theo `level`.
            'plo_achieved' => $ploResults->where('passed', true)->count(),
            'plo_total' => $ploResults->count(),
            'pi_achieved' => collect($piBreakdown['pis'])->where('passed', true)->count(),
            'pi_total' => count($piBreakdown['pis']),
        ];

        return Inertia::render('Manager/Students/Show', [
            'student' => [
                'uuid' => $student->uuid,
                'student_code' => $student->student_code,
                'full_name' => $student->full_name,
                'email' => $student->email,
                'phone' => $student->phone,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->format('d/m/Y'),
                'status' => $student->status,
                // Mã tra cứu để khoa đọc cho học viên khi họ mất phiếu — CHỈ người vận hành thấy.
                // Mã này là bí mật riêng của từng học viên: ai cầm mã là xem được kết quả của
                // người đó. Trang này nay mở cho vai trò `viewer` (cố vấn học tập) nên nếu vẫn
                // trả mã xuống thì mở trang = phát mã của cả trường.
                'lookup_code' => ($request->user()?->hasRole('super_admin') && $student->lookup_code)
                    ? app(StudentLookupService::class)->format($student->lookup_code)
                    : null,
                'program' => $student->program ? [
                    'name' => $student->program->name,
                    'code' => $student->program->code,
                ] : null,
                'cohort' => $student->cohort ? [
                    'name' => $student->cohort->name,
                    'start_year' => $student->cohort->start_year,
                ] : null,
            ],
            'classes' => $classesData,
            'ploResults' => $ploResults,
            'piResults' => $piBreakdown['pis'],
            // Người xem (cố vấn học tập) không có nút nào chạm tới mã tra cứu.
            'canManage' => (bool) $request->user()?->hasRole('super_admin'),
            'obe' => [
                'is_ratio' => $piBreakdown['is_ratio'],
                'threshold' => $piBreakdown['threshold'],
                'clo_pass_mark' => $cloPassMark,
                'course_pass_mark' => $coursePassMark,
                'grading_note' => $gradingNote,
            ],
            'progress' => $tienDo,
            'stats' => $stats,
        ]);
    }
}
