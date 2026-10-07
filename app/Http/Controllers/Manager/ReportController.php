<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Exports\AunQaReportExport;
use App\Exports\StructureTemplateExport;
use App\Http\Controllers\Controller;
use App\Jobs\Calculations\GenerateReportJob;
use App\Jobs\Calculations\RecalculateObeJob;
use App\Jobs\Imports\ImportAggregateGradeJob;
use App\Jobs\Imports\ImportBulkGradeJob;
use App\Jobs\Imports\ImportLegacyGradeJob;
use App\Jobs\Imports\ImportQuestionGradeJob;
use App\Jobs\Imports\ImportStructureJob;
use App\Jobs\Imports\ImportStudentRosterJob;
use App\Jobs\Imports\ImportSurveyJob;
use App\Enums\CohortStrategy;
use App\Models\Cohort;
use App\Models\CourseClass;
use App\Models\FileBatch;
use App\Models\MeasurementRun;
use App\Models\Program;
use App\Models\ReportSnapshot;
use App\Models\StudentGrade;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\ObeReadinessService;
use App\Support\WorkingSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Trường đang làm việc cho các thao tác GHI (import/đo); null = chưa gán trường → chặn. */
    private function requireSchoolId(Request $request): int
    {
        $sid = WorkingSchool::id($request);
        abort_if($sid === null, 403, 'Bạn chưa được gán trường làm việc — liên hệ quản trị.');

        return $sid;
    }

    public function index(Request $request, ObeAttainmentService $attainmentService): Response
    {
        // Ngữ cảnh trường đã kẹp per-user (server-side). null (user chưa gán trường) → mọi list rỗng.
        $sid = WorkingSchool::id($request);

        // Live preview "mức đạt PLO" khi đã chọn Ngành (+ Khóa) — chỉ khi ngành thuộc trường đang làm việc.
        $attainment = null;
        $piAttainment = null;
        $viewProgramId = $request->integer('view_program_id') ?: null;
        $viewCohortId = $request->integer('view_cohort_id') ?: null;
        if ($viewProgramId && Program::where('id', $viewProgramId)->where('school_id', $sid)->exists()) {
            $attainment = $attainmentService->ploAttainment($viewProgramId, $viewCohortId);
            $piAttainment = $attainmentService->piAttainment($viewProgramId, $viewCohortId);
        }

        $snapshots = ReportSnapshot::where('type', 'aun_qa')
            ->where('school_id', $sid)
            ->latest()
            ->paginate(15)
            ->through(fn(ReportSnapshot $s) => [
                'uuid' => $s->uuid,
                'school_id' => $s->school_id,
                'name' => $s->name,
                'status' => $s->status,
                'downloadable' => $s->status === 'generated' && !empty($s->file_path),
                'created_at' => $s->created_at?->format('d/m/Y H:i'),
                'created_ago' => $s->created_at?->locale('vi')->diffForHumans(),
                'is_stale' => in_array($s->status, ['queued', 'processing'], true) && $s->created_at?->lt(now()->subMinutes(10)),
            ]);

        $bulkBatches = FileBatch::whereIn('type', ['grade_bulk', 'grade_question', 'class_sheet'])
            ->where('user_id', Auth::id())
            ->where('school_id', $sid)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (FileBatch $b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'school_id' => $b->school_id,
                'file_name' => $b->file_name,
                'status' => $b->status,
                'processed_rows' => $b->processed_rows,
                'total_rows' => $b->total_rows,
                'error_log' => $b->error_log ?? [],
                'summary' => $b->summary,
                'downloadable' => !empty($b->file_path),
                'created_at' => $b->created_at?->format('d/m/Y H:i'),
                'created_ago' => $b->created_at?->locale('vi')->diffForHumans(),
                'is_stale' => in_array($b->status, ['queued', 'processing'], true) && $b->created_at?->lt(now()->subMinutes(10)),
            ]);

        $structureBatches = FileBatch::whereIn('type', ['structure', 'course_frame'])
            ->where('user_id', Auth::id())
            ->where('school_id', $sid)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (FileBatch $b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'school_id' => $b->school_id,
                'file_name' => $b->file_name,
                'status' => $b->status,
                'processed_rows' => $b->processed_rows,
                'total_rows' => $b->total_rows,
                'error_log' => $b->error_log ?? [],
                'summary' => $b->summary,
                'downloadable' => !empty($b->file_path),
                'created_at' => $b->created_at?->format('d/m/Y H:i'),
                'created_ago' => $b->created_at?->locale('vi')->diffForHumans(),
                'is_stale' => in_array($b->status, ['queued', 'processing'], true) && $b->created_at?->lt(now()->subMinutes(10)),
            ]);

        $rosterBatches = FileBatch::where('type', 'student_roster')
            ->where('user_id', Auth::id())
            ->where('school_id', $sid)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (FileBatch $b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'school_id' => $b->school_id,
                'file_name' => $b->file_name,
                'status' => $b->status,
                'processed_rows' => $b->processed_rows,
                'total_rows' => $b->total_rows,
                'error_log' => $b->error_log ?? [],
                'summary' => $b->summary,
                'downloadable' => !empty($b->file_path),
                'created_at' => $b->created_at?->format('d/m/Y H:i'),
                'created_ago' => $b->created_at?->locale('vi')->diffForHumans(),
                'is_stale' => in_array($b->status, ['queued', 'processing'], true) && $b->created_at?->lt(now()->subMinutes(10)),
            ]);

        $surveyBatches = FileBatch::where('type', 'survey')
            ->where('user_id', Auth::id())
            ->where('school_id', $sid)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (FileBatch $b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'school_id' => $b->school_id,
                'file_name' => $b->file_name,
                'status' => $b->status,
                'processed_rows' => $b->processed_rows,
                'total_rows' => $b->total_rows,
                'error_log' => $b->error_log ?? [],
                'summary' => $b->summary,
                'downloadable' => !empty($b->file_path),
                'created_at' => $b->created_at?->format('d/m/Y H:i'),
                'created_ago' => $b->created_at?->locale('vi')->diffForHumans(),
                'is_stale' => in_array($b->status, ['queued', 'processing'], true) && $b->created_at?->lt(now()->subMinutes(10)),
            ]);

        // Lớp CHƯA nhập điểm nào (việc cụ thể mà todo "X lớp chưa nhập điểm" trỏ tới).
        // Lọc client-side theo trường như các list khác.
        $ungradedClasses = CourseClass::whereNotIn('id', StudentGrade::query()->select('class_id')->distinct())
            ->where('school_id', $sid)
            ->with(['syllabus.course:id,code,name', 'cohort:id,name,start_year', 'lecturer:id,full_name'])
            ->limit(200)
            ->get()
            ->map(fn (CourseClass $c) => [
                'class_code' => $c->class_code,
                'school_id' => $c->school_id,
                'course_code' => $c->syllabus?->course?->code,
                'course_name' => $c->syllabus?->course?->name,
                'lecturer_name' => $c->lecturer?->full_name,
                'cohort_name' => $c->cohort?->name,
                'phuong_an' => $c->cohort ? CohortStrategy::fromStartYear((int) $c->cohort->start_year)->label() : null,
            ]);

        return Inertia::render('Manager/Reports/Index', [
            // $sid null (user chưa được gán trường) → 0 → mọi con số về 0, thẻ hiện "chưa dựng
            // khung". Cùng kiểu xuống thang với các prop khác bên dưới.
            'structureOverview' => app(\App\Services\OBE\StructureOverviewService::class)->forSchool((int) $sid),
            // Có trường mẫu nào đang tồn tại không — để nút "Xóa dữ liệu mẫu" luôn với tới được,
            // kể cả khi trường đang mở đã dựng khung (trường mẫu thì lúc nào cũng đã dựng khung).
            'demoLoaded' => \App\Models\School::withTrashed()
                ->whereIn('code', array_merge(
                    array_column(\App\Services\Demo\DemoSchools::all(), 'code'),
                    [\App\Services\Demo\DemoSchools::LEGACY_CODE],
                ))->exists(),
            'snapshots' => $snapshots,
            'surveyBatches' => $surveyBatches,
            'ungradedClasses' => $ungradedClasses,
            'schools' => WorkingSchool::switchableSchools($request->user()),
            'programs' => Program::select('id', 'code', 'name', 'school_id')->where('school_id', $sid)->orderBy('name')->get(),
            // Cho ô "tải phiếu lớp học phần điền sẵn" ở Bước 2 — lọc môn theo ngành đã chọn.
            'courses' => \App\Models\Course::with('programs:id')->where('school_id', $sid)->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'program_ids' => $c->programs->pluck('id')->all()]),
            'cohorts' => Cohort::select('id', 'name', 'start_year', 'program_id', 'school_id')->where('school_id', $sid)->orderBy('name')->get(),
            'attainment' => $attainment,
            'piAttainment' => $piAttainment,
            'viewFilters' => ['view_program_id' => $viewProgramId, 'view_cohort_id' => $viewCohortId],
            'bulkBatches' => $bulkBatches,
            'structureBatches' => $structureBatches,
            'rosterBatches' => $rosterBatches,
            'measurementRuns' => MeasurementRun::with('program:id,code', 'cohort:id,name', 'user:id,full_name')
                ->where('school_id', $sid)
                ->latest()->limit(10)->get()
                ->map(fn (MeasurementRun $m) => [
                    'school_id' => $m->school_id,
                    'program' => $m->program?->code,
                    'cohort' => $m->cohort?->name,
                    'source' => $m->source,
                    'pairs_count' => $m->pairs_count,
                    'user' => $m->user?->full_name,
                    'created_at' => $m->created_at?->format('d/m/Y H:i'),
                ]),
        ]);
    }

    /** Dựng / cập nhật khung cấu trúc CTĐT từ 1 file Excel nhiều sheet. */
    public function importStructure(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $original = $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('imports', 'structure_' . time() . '_' . $original);

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'school_id' => $this->requireSchoolId($request),
            'class_id' => null,
            'file_name' => $original,
            'file_path' => $path,
            'type' => 'structure',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportStructureJob::dispatch($batch, $this->requireSchoolId($request));

        return redirect()->back()->with('success', 'Đã tải lên khung cấu trúc và đưa vào hàng đợi xử lý.');
    }

    /** File mẫu KHAI BÁO CHƯƠNG TRÌNH (4 sheet). */
    public function structureTemplate(): StreamedResponse
    {
        return $this->xlsxDownload((new StructureTemplateExport())->build(), 'khai_bao_chuong_trinh.xlsx');
    }

    /**
     * File mẫu PHIẾU LỚP HỌC PHẦN. Có chọn ngành / khóa / môn thì phiếu được điền sẵn từ dữ liệu
     * của TRƯỜNG ĐANG LÀM VIỆC (id của trường khác bị bỏ qua, không lộ dữ liệu).
     */
    public function classSheetTemplate(Request $request): StreamedResponse
    {
        $sid = WorkingSchool::id($request);
        $courseId = $request->integer('course_id') ?: null;

        $book = (new \App\Exports\ClassSheetTemplate())->build(
            $sid,
            $request->integer('program_id') ?: null,
            $request->integer('cohort_id') ?: null,
            $courseId,
        );

        $course = $courseId && $sid ? \App\Models\Course::where('school_id', $sid)->find($courseId) : null;
        $name = 'phieu_lop_hoc_phan' . ($course ? '_' . \Illuminate\Support\Str::slug($course->code, '_') : '') . '.xlsx';

        return $this->xlsxDownload($book, $name);
    }

    /**
     * File mẫu KHUNG HỌC PHẦN THEO ĐỢT — kỹ thuật của trường khai CLO, ma trận CLO, bài đánh giá và lớp
     * một lần cho cả khóa. Chọn ngành, khóa thì điền sẵn từ TRƯỜNG ĐANG LÀM VIỆC.
     */
    public function courseFrameTemplate(Request $request): StreamedResponse
    {
        $sid = WorkingSchool::id($request);
        $programId = $request->integer('program_id') ?: null;
        $cohortId = $request->integer('cohort_id') ?: null;

        $book = (new \App\Exports\CourseFrameTemplate())->build($sid, $programId, $cohortId);

        $program = $programId && $sid ? Program::where('school_id', $sid)->find($programId) : null;
        $cohort = $program && $cohortId ? Cohort::where('program_id', $program->id)->find($cohortId) : null;
        $name = 'khung_hoc_phan'
            . ($program ? '_' . \Illuminate\Support\Str::slug($program->code, '_') : '')
            . ($cohort ? '_' . \Illuminate\Support\Str::slug($cohort->name, '_') : '')
            . '.xlsx';

        return $this->xlsxDownload($book, $name);
    }

    /** Nhận một hoặc NHIỀU file khung (vd mỗi khoa một file) — mỗi file một lượt xử lý riêng. */
    public function importCourseFrame(Request $request): RedirectResponse
    {
        $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'file|mimes:xlsx,xls|max:20480',
        ]);
        $sid = $this->requireSchoolId($request);

        foreach ($request->file('files') as $file) {
            $original = $file->getClientOriginalName();
            $batch = FileBatch::create([
                'user_id' => Auth::id(),
                'school_id' => $sid,
                'class_id' => null,
                'file_name' => $original,
                'file_path' => $file->storeAs('imports', 'course_frame_' . time() . '_' . \Illuminate\Support\Str::random(6) . '_' . $original),
                'type' => 'course_frame',
                'status' => 'queued',
                'processed_rows' => 0,
                'total_rows' => 0,
            ]);
            \App\Jobs\Imports\ImportCourseFrameJob::dispatch($batch, $sid);
        }

        $n = count($request->file('files'));

        return redirect()->back()->with('success', "Đã tải lên {$n} file khung học phần. Hệ thống kiểm từng file; file nào có lỗi sẽ không được nhập và báo lỗi theo từng ô.");
    }

    /**
     * Nhận NHIỀU phiếu một lần — mỗi phiếu một lớp, mỗi phiếu một lượt xử lý riêng.
     * `bo_sung`: phiếu bổ sung sau thi lại — chỉ cập nhật học viên có trong phiếu.
     */
    public function importClassSheets(Request $request): RedirectResponse
    {
        $request->validate([
            'files' => 'required|array|min:1|max:50',
            'files.*' => 'file|mimes:xlsx,xls|max:20480',
            'bo_sung' => 'nullable|boolean',
        ]);
        $sid = $this->requireSchoolId($request);
        $boSung = $request->boolean('bo_sung');

        foreach ($request->file('files') as $file) {
            $original = $file->getClientOriginalName();
            $batch = FileBatch::create([
                'user_id' => Auth::id(),
                'school_id' => $sid,
                'class_id' => null,
                'file_name' => $original,
                'file_path' => $file->storeAs('imports', 'class_sheet_' . time() . '_' . \Illuminate\Support\Str::random(6) . '_' . $original),
                'type' => 'class_sheet',
                'status' => 'queued',
                'processed_rows' => 0,
                'total_rows' => 0,
            ]);
            \App\Jobs\Imports\ImportClassSheetJob::dispatch($batch, $sid, ['bo_sung' => $boSung]);
        }

        $n = count($request->file('files'));
        $loai = $boSung ? 'phiếu bổ sung' : 'phiếu lớp học phần';

        return redirect()->back()->with('success', "Đã tải lên {$n} {$loai}. Hệ thống kiểm từng phiếu; phiếu nào có lỗi sẽ không được nhập và báo lỗi theo từng ô.");
    }

    private function xlsxDownload(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $name): StreamedResponse
    {
        return response()->streamDownload(
            fn () => (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output'),
            $name,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function generate(Request $request, ObeReadinessService $readiness): RedirectResponse
    {
        $sid = $this->requireSchoolId($request);
        // Ngành/khóa PHẢI thuộc trường đang làm việc (chặn forge sang trường khác).
        $validated = $request->validate([
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $sid)],
            'cohort_id' => ['required', Rule::exists('cohorts', 'id')->where('school_id', $sid)],
        ]);

        // CHẶN tạo báo cáo khi khung chưa đủ điều kiện đo (tránh báo cáo rỗng).
        $r = $readiness->check((int) $validated['program_id'], (int) $validated['cohort_id']);
        if (empty($r['ready'])) {
            $missing = collect($r['checks'] ?? [])
                ->filter(fn ($c) => ($c['severity'] ?? 'error') === 'error' && empty($c['ok']))
                ->map(fn ($c) => $c['label'] ?? '')
                ->filter()
                ->implode('; ');

            return redirect()->back()->with('error',
                'Chưa đủ điều kiện đo lường cho ngành/khóa này — chưa tạo báo cáo. Thiếu: '
                . ($missing !== '' ? $missing : 'cấu trúc khung')
                . '. Mở “Kiểm tra sẵn sàng” (Bước 3) để xem chi tiết.');
        }

        $program = Program::findOrFail($validated['program_id']);
        $cohort = Cohort::findOrFail($validated['cohort_id']);

        $snapshot = ReportSnapshot::create([
            'user_id' => Auth::id(),
            'school_id' => $program->school_id,
            'name' => 'Báo cáo AUN-QA - Ngành ' . $program->name . ' - Khóa ' . $cohort->name,
            'type' => 'aun_qa',
            'filters' => ['program_id' => $program->id, 'cohort_id' => $cohort->id],
            'status' => 'queued',
        ]);

        GenerateReportJob::dispatch($snapshot);

        return redirect()->back()->with('success', 'Đã kích hoạt tạo báo cáo AUN-QA. Báo cáo sẽ sẵn sàng sau ít phút.');
    }

    public function recalculate(Request $request): RedirectResponse
    {
        $sid = $this->requireSchoolId($request);
        $validated = $request->validate([
            'program_id' => ['nullable', Rule::exists('programs', 'id')->where('school_id', $sid)],
            'cohort_id' => ['nullable', Rule::exists('cohorts', 'id')->where('school_id', $sid)],
        ]);

        $programId = isset($validated['program_id']) ? (int) $validated['program_id'] : null;
        $cohortId = isset($validated['cohort_id']) ? (int) $validated['cohort_id'] : null;
        // Đo lại chạy trên queue 'measurements' riêng (worker riêng) để không block
        // import/export người dùng trên queue 'default'.
        RecalculateObeJob::dispatch($sid, $programId, $cohortId, Auth::id(), 'manual')
            ->onQueue('measurements');

        return redirect()->back()->with('success', 'Đã kích hoạt tính lại CLO/PI/PLO cho phạm vi đã chọn. Kết quả sẽ cập nhật sau ít phút.');
    }

    /**
     * Preflight kiểm tra sẵn sàng đo lường (read-only) cho 1 ngành + khóa.
     * Trả JSON cho card "Kiểm tra sẵn sàng đo lường" ở trang Báo cáo.
     */
    public function readiness(Request $request, ObeReadinessService $readiness): JsonResponse
    {
        $sid = $this->requireSchoolId($request);
        $validated = $request->validate([
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $sid)],
            'cohort_id' => ['required', Rule::exists('cohorts', 'id')->where('school_id', $sid)],
        ]);

        return response()->json(
            $readiness->check((int) $validated['program_id'], (int) $validated['cohort_id'])
        );
    }

    public function bulkImportGrades(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $original = $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('imports', 'bulk_grades_' . time() . '_' . $original);

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'school_id' => $this->requireSchoolId($request),
            'class_id' => null,
            'file_name' => $original,
            'file_path' => $path,
            'type' => 'grade_bulk',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportBulkGradeJob::dispatch($batch, $this->requireSchoolId($request));

        return redirect()->back()->with('success', 'Đã tải lên bảng điểm hàng loạt và đưa vào hàng đợi xử lý.');
    }

    /**
     * Nhập điểm khóa cũ (≤2024) TỰ CHỨA — chỉ cần file điểm, không phải chọn khóa.
     * Hệ thống tự xác lập khóa/lớp/SV từ file. Cần Ngành + Môn + CLO có sẵn.
     */
    public function importLegacyGrades(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $original = $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('imports', 'legacy_grades_' . time() . '_' . $original);

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'school_id' => $this->requireSchoolId($request),
            'class_id' => null,
            'file_name' => $original,
            'file_path' => $path,
            'type' => 'grade_bulk',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportLegacyGradeJob::dispatch($batch, $this->requireSchoolId($request));

        return redirect()->back()->with('success', 'Đã tải lên bảng điểm khóa cũ — hệ thống tự dựng khóa/lớp và tính CLO.');
    }

    /**
     * Nhập ĐIỂM TỔNG học phần (TỰ CHỨA) cho trường CHỈ có điểm tổng — vẫn đo lên PI/PLO
     * cấp chương trình. Đánh dấu khóa 'total' + gán điểm tổng cho mọi CLO của môn.
     * Cần Ngành + Môn + CLO; muốn ra PI/PLO cần thêm PLO/PI + ma trận CLO→PI (Bước 1).
     */
    public function importAggregateGrades(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $original = $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('imports', 'aggregate_grades_' . time() . '_' . $original);

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'school_id' => $this->requireSchoolId($request),
            'class_id' => null,
            'file_name' => $original,
            'file_path' => $path,
            'type' => 'grade_bulk',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportAggregateGradeJob::dispatch($batch, $this->requireSchoolId($request));

        return redirect()->back()->with('success', 'Đã tải lên bảng điểm tổng — hệ thống tự dựng khóa/lớp và đo lên PI/PLO.');
    }

    /**
     * PA4 — Nhập điểm THEO CÂU HỎI (rubric): mỗi dòng là 1 câu hỏi (bài nào, đo CLO nào, đạt/tối đa).
     * Đây là nguồn ĐO CHUẨN ĐẦU RA. Điểm số (điểm tổng kết môn) nhập riêng bằng PA2.
     */
    public function importQuestionGrades(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
        ]);

        $original = $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('imports', 'question_grades_' . time() . '_' . $original);

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'school_id' => $this->requireSchoolId($request),
            'class_id' => null,
            'file_name' => $original,
            'file_path' => $path,
            'type' => 'grade_question',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportQuestionGradeJob::dispatch($batch, $this->requireSchoolId($request));

        return redirect()->back()->with('success', 'Đã tải lên điểm theo câu hỏi — hệ thống sẽ đo CLO/PI/PLO từ rubric.');
    }

    /** Nhập kết quả khảo sát (đo GIÁN TIẾP) theo PLO cho 1 trường. */
    public function importSurvey(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $original = $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('imports', 'survey_' . time() . '_' . $original);

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'school_id' => $this->requireSchoolId($request),
            'class_id' => null,
            'file_name' => $original,
            'file_path' => $path,
            'type' => 'survey',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportSurveyJob::dispatch($batch, $this->requireSchoolId($request));

        return redirect()->back()->with('success', 'Đã tải lên kết quả khảo sát (đo gián tiếp) và đưa vào hàng đợi.');
    }

    /**
     * Nhập DANH SÁCH SINH VIÊN hàng loạt cho 1 trường (thay vì thêm tay từng SV).
     * Chỉ ghi students; cần Ngành có sẵn (Bước 1). MSSV trùng → cập nhật.
     */
    public function importStudentRoster(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $original = $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('imports', 'student_roster_' . time() . '_' . $original);

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'school_id' => $this->requireSchoolId($request),
            'class_id' => null,
            'file_name' => $original,
            'file_path' => $path,
            'type' => 'student_roster',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportStudentRosterJob::dispatch($batch, $this->requireSchoolId($request));

        return redirect()->back()->with('success', 'Đã tải lên danh sách sinh viên và đưa vào hàng đợi xử lý.');
    }

    /** Template file khảo sát đo gián tiếp. */
    public function surveyTemplate(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template_khao_sat_gian_tiep.csv"',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, ['Mã ngành', 'Mã khóa', 'Đối tượng', 'Mã PLO', 'Số phản hồi', 'Điểm TB Likert', 'Thang']);
            fputcsv($file, ['CNTT', '', 'Nhà tuyển dụng', 'PLO1', '35', '4.2', '5']);
            fputcsv($file, ['CNTT', '', 'Cựu sinh viên', 'PLO1', '50', '3.9', '5']);
            fputcsv($file, ['CNTT', 'K2022', 'Tốt nghiệp', 'PLO2', '40', '4.0', '5']);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /** Nạp dữ liệu mẫu (các trường mang nhãn [DEMO]) để thử toàn bộ quy trình. */
    public function loadDemo(\App\Services\Demo\DemoDataService $demo): RedirectResponse
    {
        $school = $demo->seed();
        $so = count(\App\Services\Demo\DemoSchools::all());

        return redirect()->back()->with('success',
            'Đã nạp ' . $so . ' trường mẫu, đang mở "' . $school->name . '". Đổi ở ô "Trường đang làm việc" để xem từng trường: khoa, ngành, môn, điểm, mức đạt, báo cáo. Xong bấm "Xóa dữ liệu mẫu".');
    }

    /** Xóa toàn bộ dữ liệu mẫu (chỉ các trường mang nhãn [DEMO]). */
    public function clearDemo(\App\Services\Demo\DemoDataService $demo): RedirectResponse
    {
        $removed = $demo->clear();

        return redirect()->back()->with('success',
            $removed ? 'Đã xóa toàn bộ trường mẫu.' : 'Không có dữ liệu mẫu để xóa.');
    }

    /** Xuất báo cáo AUN-QA dạng Excel nhiều sheet (PLO + PI + ma trận) theo ngành/khóa. */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'program_id' => 'required|exists:programs,id',
            'cohort_id' => 'nullable|exists:cohorts,id',
        ]);

        $program = Program::findOrFail($validated['program_id']);
        $cohortId = isset($validated['cohort_id']) ? (int) $validated['cohort_id'] : null;
        $name = 'aun_qa_' . $program->code . ($cohortId ? '_k' . $cohortId : '') . '.xlsx';

        return Excel::download(new AunQaReportExport((int) $validated['program_id'], $cohortId), $name);
    }

    /**
     * Tải báo cáo AUN-QA đã sinh.
     *
     * PHẢI kẹp theo trường đang làm việc. Trước đây hàm này chỉ kiểm trạng thái và sự tồn tại của
     * file, không kiểm trường — mà route lại nằm trong nhóm `viewer` được vào, nên người xem của
     * trường A đổi uuid trên URL là tải được báo cáo của trường B: đủ họ tên, MSSV và kết quả
     * chuẩn đầu ra của toàn khóa. Ngay cả với người vận hành, đây cũng là cách gửi nhầm báo cáo
     * của trường này cho trường kia.
     */
    public function download(Request $request, ReportSnapshot $snapshot): StreamedResponse
    {
        abort_if((int) $snapshot->school_id !== (int) WorkingSchool::id($request), 404);
        abort_unless($snapshot->status === 'generated', 404);
        abort_unless($snapshot->file_path && Storage::exists($snapshot->file_path), 404);

        return Storage::download($snapshot->file_path, basename($snapshot->file_path));
    }

    /**
     * Tải lại file gốc đã nhập (lưu trữ — không xóa sau xử lý).
     * Kẹp theo trường vì lý do y hệt `download()`: file điểm gốc của trường khác không được lọt ra.
     */
    public function downloadBatchFile(Request $request, FileBatch $batch): StreamedResponse
    {
        abort_if((int) $batch->school_id !== (int) WorkingSchool::id($request), 404);
        abort_unless($batch->file_path && Storage::exists($batch->file_path), 404);

        return Storage::download($batch->file_path, $batch->file_name ?? basename($batch->file_path));
    }
}
