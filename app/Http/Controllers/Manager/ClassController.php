<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreCourseClassRequest;
use App\Http\Requests\Manager\UpdateCourseClassRequest;
use App\Jobs\Imports\ImportEnrollmentJob;
use App\Jobs\Imports\ImportGradeJob;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\FileBatch;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentRubricScore;
use App\Services\EnrollmentService;
use App\Services\Manager\ClassMetricsService;
use App\Support\LecturerScope;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClassController extends Controller
{
    public function __construct(private readonly ClassMetricsService $metrics)
    {
    }

    public function index(Request $request): Response
    {
        $sid = WorkingSchool::id($request);
        $classesQuery = CourseClass::with(['syllabus.course', 'syllabus.clos', 'cohort', 'syllabus.assessments', 'lecturer'])
            ->where('school_id', $sid);

        // Giảng viên chỉ thấy lớp mình dạy. Kẹp ở TRUY VẤN — bộ lọc `lecturer_id` bên dưới là
        // do người dùng tự chọn nên không chặn được gì.
        LecturerScope::classes($classesQuery, $request);

        if ($request->filled('semester')) {
            $classesQuery->where('semester', $request->semester);
        }
        if ($request->filled('academic_year')) {
            $classesQuery->where('academic_year', $request->academic_year);
        }
        if ($request->filled('cohort_id')) {
            $classesQuery->where('cohort_id', $request->cohort_id);
        }
        if ($request->filled('lecturer_id')) {
            $classesQuery->where('lecturer_id', $request->lecturer_id);
        }
        if ($request->filled('q')) {
            $search = $request->q;
            $classesQuery->where(function ($q) use ($search) {
                $q->where('class_code', 'ilike', "%{$search}%")
                  ->orWhereHas('syllabus.course', fn($cq) =>
                      $cq->where('name', 'ilike', "%{$search}%"))
                  ->orWhereHas('lecturer', fn($lq) =>
                      $lq->where('full_name', 'ilike', "%{$search}%"));
            });
        }

        // Get ALL matching IDs for batch stats (before pagination)
        $allClassIds = (clone $classesQuery)->pluck('id')->toArray();

        // Student counts for ALL matching classes (for summary stats)
        $allStudentCounts = DB::table('course_class_student')
            ->whereIn('class_id', $allClassIds)
            ->select('class_id', DB::raw('COUNT(DISTINCT student_id) as cnt'))
            ->groupBy('class_id')
            ->pluck('cnt', 'class_id');

        $stats = [
            'total_classes' => count($allClassIds),
            'total_students' => $allStudentCounts->sum(),
            'total_capacity' => CourseClass::whereIn('id', $allClassIds)->sum('max_capacity'),
            'total_lecturers' => !empty($allClassIds)
                ? CourseClass::whereIn('id', $allClassIds)->distinct('lecturer_id')->count('lecturer_id')
                : 0,
        ];

        // Paginate
        $perPage = $request->input('per_page', 15);
        $classes = $classesQuery->paginate($perPage);

        $classes->getCollection()->transform(function ($class) use ($allStudentCounts) {
            return [
                'id' => $class->id,
                'uuid' => $class->uuid,
                'class_code' => $class->class_code,
                'course_name' => $class->syllabus?->course?->name ?? '',
                'course_code' => $class->syllabus?->course?->code ?? '',
                'credits' => $class->syllabus?->course?->credits ?? 0,
                'cohort_name' => $class->cohort?->name ?? '',
                'semester' => $class->semester,
                'academic_year' => $class->academic_year,
                'student_count' => $allStudentCounts->get($class->id, 0),
                'max_capacity' => $class->max_capacity,
                'lecturer_name' => $class->lecturer?->full_name ?? '',
                // Raw FKs for the edit form
                'syllabus_id' => $class->syllabus_id,
                'cohort_id' => $class->cohort_id,
                'lecturer_id' => $class->lecturer_id,
                'start_date' => $class->start_date?->format('Y-m-d'),
                'end_date' => $class->end_date?->format('Y-m-d'),
            ];
        });

        $yearOptions = CourseClass::where('school_id', $sid)->select('academic_year')
            ->distinct()
            ->orderBy('academic_year', 'desc')
            ->pluck('academic_year');

        $lecturers = \App\Models\User::role('lecturer')
            ->whereHas('department', fn($q) => $q->where('school_id', $sid))
            ->select('id', 'full_name')
            ->get()
            ->map(fn($u) => ['value' => $u->id, 'label' => $u->full_name]);

        $cohortOptions = \App\Models\Cohort::where('school_id', $sid)->select('id', 'name')
            ->get()
            ->map(fn($c) => ['value' => $c->id, 'label' => $c->name]);

        $syllabusOptions = \App\Models\CourseSyllabus::with('course')
            ->whereHas('course', fn($q) => $q->where('school_id', $sid))
            ->orderBy('course_id')
            ->get()
            ->map(fn($s) => [
                'value' => $s->id,
                'label' => ($s->course?->code ?? '') . ' — ' . ($s->course?->name ?? '') . ' (v' . $s->version . ')',
            ]);

        return Inertia::render('Manager/Classes/Index', [
            'classes' => $classes,
            'stats' => $stats,
            'semesterOptions' => [
                ['value' => '1', 'label' => 'Học kỳ 1'],
                ['value' => '2', 'label' => 'Học kỳ 2'],
            ],
            'yearOptions' => $yearOptions,
            'cohortOptions' => $cohortOptions,
            'lecturerOptions' => $lecturers,
            'syllabusOptions' => $syllabusOptions,
            'currentFilters' => $request->only('semester', 'academic_year', 'cohort_id', 'lecturer_id', 'q', 'per_page'),
        ]);
    }

    public function show(Request $request, CourseClass $courseClass): Response
    {
        abort_if((int) $courseClass->school_id !== (int) WorkingSchool::id($request), 404);
        LecturerScope::assertOwns($courseClass, $request);

        $courseClass->load([
            'syllabus.course',
            'syllabus.clos',
            'syllabus.assessments.rubric.criteria.clo',
            'cohort.program',
            'lecturer',
        ]);

        $students = $courseClass->students()->get();
        $studentIds = $students->pluck('id')->toArray();

        // All enrollments for status counts (before pagination)
        $allEnrollments = Enrollment::where('class_id', $courseClass->id)->get();
        $enrollmentStatusCounts = [
            'active' => $allEnrollments->where('status', 'active')->count(),
            'locked' => $allEnrollments->where('status', 'locked')->count(),
            'suspended' => $allEnrollments->where('status', 'suspended')->count(),
            'completed' => $allEnrollments->where('status', 'completed')->count(),
            'withdrawn' => $allEnrollments->where('status', 'withdrawn')->count(),
        ];
        $activeCount = $enrollmentStatusCounts['active'];

        // Paginated enrollments for table
        $enrollments = Enrollment::where('class_id', $courseClass->id)
            ->with('student')
            ->paginate(20)
            ->through(fn(Enrollment $e) => [
                'id' => $e->id,
                'student_code' => $e->student->student_code,
                'full_name' => $e->student->full_name,
                'status' => $e->status,
                'enrolled_at' => $e->enrolled_at?->format('d/m/Y H:i'),
                'uuid' => $e->student->uuid,
            ]);

        // Grades
        $grades = StudentGrade::where('class_id', $courseClass->id)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('student_id');

        // Rubric scores
        $criteriaIds = $courseClass->syllabus->assessments
            ->where('is_rubric_based', true)
            ->flatMap(fn($a) => $a->rubric ? $a->rubric->criteria->pluck('id') : [])
            ->toArray();

        $rubricScores = collect();
        if (!empty($criteriaIds)) {
            $rubricScores = StudentRubricScore::whereIn('student_id', $studentIds)
                ->whereIn('rubric_criteria_id', $criteriaIds)
                ->get();
        }

        // CLO results
        $cloResults = DB::table('student_clo_results')
            ->where('class_id', $courseClass->id)
            ->whereIn('student_id', $studentIds)
            ->join('clos', 'student_clo_results.clo_id', '=', 'clos.id')
            ->select('student_id', 'clos.code as clo_code', 'clos.description as clo_description', 'achieved_score')
            ->get()
            ->groupBy('student_id');

        // Build student performance + class metrics (delegated to ClassMetricsService)
        $studentPerformance = $this->metrics->studentPerformance($courseClass, $students, $grades, $cloResults);
        $gradeDistribution = $this->metrics->gradeDistribution($courseClass->id);
        $cloAchievement = $this->metrics->cloAchievement($courseClass->id);

        $avgScores = $studentPerformance->pluck('avg_score')->filter()->values();
        $passedCount = $studentPerformance->filter(fn($s) => $s['passed'])->count();

        $fileBatches = FileBatch::where('class_id', $courseClass->id)
            ->where('type', 'enrollment')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn(FileBatch $b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'file_name' => $b->file_name,
                'status' => $b->status,
                'processed_rows' => $b->processed_rows,
                'total_rows' => $b->total_rows,
                'error_log' => $b->error_log,
                'created_at' => $b->created_at->format('d/m/Y H:i'),
            ]);

        $gradeBatches = FileBatch::where('class_id', $courseClass->id)
            ->whereIn('type', ['grade', 'class_sheet'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn(FileBatch $b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'file_name' => $b->file_name,
                'status' => $b->status,
                'processed_rows' => $b->processed_rows,
                'total_rows' => $b->total_rows,
                'error_log' => $b->error_log,
                'created_at' => $b->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Manager/Classes/Show', [
            // Bảng 3 của khách: mức đạt PI TRONG học phần này, kèm tách bài quá trình / cuối kỳ.
            // KHÔNG cộng lại thành PI chương trình — xem docblock CoursePiAttainmentService.
            'piAttainment' => app(\App\Services\OBE\CoursePiAttainmentService::class)->forClass($courseClass),
            // Khách (buổi họp lần 1): "học phần có 1, 3, 5, 10, 20 lớp không biết — chỉ quan tâm học phần thôi".
            // Gộp mọi lớp CÙNG MÔN, CÙNG KHÓA thành số của học phần. Chỉ có một lớp thì trùng bảng trên, bỏ qua.
            'courseRollup' => $this->courseRollup($courseClass),
            'enrollmentStatusCounts' => $enrollmentStatusCounts,
            'gradeBatches' => $gradeBatches,
            'courseClass' => [
                'id' => $courseClass->id,
                'uuid' => $courseClass->uuid,
                'class_code' => $courseClass->class_code,
                'academic_year' => $courseClass->academic_year,
                'semester' => $courseClass->semester,
                'max_capacity' => $courseClass->max_capacity,
                'course' => [
                    'name' => $courseClass->syllabus?->course?->name ?? '',
                    'code' => $courseClass->syllabus?->course?->code ?? '',
                    'credits' => $courseClass->syllabus?->course?->credits ?? 0,
                ],
                'syllabus' => [
                    'version' => $courseClass->syllabus?->version ?? '',
                    'clos' => ($courseClass->syllabus?->clos ?? collect())->map(fn($c) => [
                        'id' => $c->id,
                        'code' => $c->code,
                        'description' => $c->description,
                    ]),
                    'assessments' => ($courseClass->syllabus?->assessments ?? collect())->map(fn($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'weight_percent' => $a->weight_percent,
                        'assessment_category' => $a->assessment_category,
                        'is_rubric_based' => $a->is_rubric_based,
                    ]),
                ],
                'cohort' => [
                    'name' => $courseClass->cohort?->name ?? '',
                    'program' => $courseClass->cohort?->program ? [
                        'name' => $courseClass->cohort->program->name,
                        'code' => $courseClass->cohort->program->code,
                    ] : null,
                ],
                'lecturer' => [
                    'full_name' => $courseClass->lecturer?->full_name ?? '',
                ],
            ],
            'enrollments' => $enrollments,
            'activeCount' => $activeCount,
            'fileBatches' => $fileBatches,
            'studentPerformance' => $studentPerformance,
            'gradeDistribution' => $gradeDistribution,
            'cloAchievement' => $cloAchievement,
            'stats' => [
                'student_count' => count($students),
                'avg_score' => $avgScores->isNotEmpty() ? round($avgScores->avg(), 2) : null,
                'max_score' => $avgScores->isNotEmpty() ? round($avgScores->max(), 2) : null,
                'min_score' => $avgScores->isNotEmpty() ? round($avgScores->min(), 2) : null,
                'passed_count' => $passedCount,
            ],
        ]);
    }

    public function store(StoreCourseClassRequest $request): RedirectResponse
    {
        CourseClass::create(array_merge($request->validated(), [
            'school_id' => WorkingSchool::id($request),
        ]));

        return redirect()->back()->with('success', 'Đã tạo lớp học phần.');
    }

    public function update(UpdateCourseClassRequest $request, CourseClass $courseClass): RedirectResponse
    {
        $courseClass->update($request->validated());

        return redirect()->back()->with('success', 'Đã cập nhật lớp học phần.');
    }

    public function destroy(CourseClass $courseClass): RedirectResponse
    {
        $courseClass->delete();

        return redirect()->back()->with('success', 'Đã xóa lớp học phần.');
    }

    public function importGrades(Request $request, CourseClass $courseClass): RedirectResponse
    {
        abort_if((int) $courseClass->school_id !== (int) WorkingSchool::id($request), 404);
        LecturerScope::assertOwns($courseClass, $request);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $fileName = 'imports/grades_' . time() . '_' . $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('imports', $fileName);

        // Legacy (≤2024) is auto-detected from the class cohort's start_year inside
        // GradeImport — no manual flag needed.
        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'class_id' => $courseClass->id,
            'file_name' => $request->file('file')->getClientOriginalName(),
            'file_path' => $path,
            'type' => 'grade',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportGradeJob::dispatch($batch, $courseClass);

        return redirect()->back()->with('success', 'Đã tải lên bảng điểm và đưa vào hàng đợi xử lý!');
    }

    /**
     * Phiếu của ĐÚNG lớp này — giáo viên tải về, điền câu hỏi và điểm. Thông tin lớp, khai báo của
     * môn và danh sách học viên của lớp đã điền sẵn.
     */
    public function sheetTemplate(Request $request, CourseClass $courseClass): StreamedResponse
    {
        abort_if((int) $courseClass->school_id !== (int) WorkingSchool::id($request), 404);
        LecturerScope::assertOwns($courseClass, $request);

        $book = (new \App\Exports\ClassSheetTemplate())->forClass($courseClass);
        $name = 'phieu_' . \Illuminate\Support\Str::slug($courseClass->class_code, '_') . '.xlsx';

        return response()->streamDownload(
            fn () => (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output'),
            $name,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Giáo viên nộp phiếu điểm của lớp mình. Đi qua đúng bộ nhập phiếu lớp; phiếu phải đúng là phiếu
     * của lớp này. `bo_sung`: phiếu bổ sung sau thi lại — chỉ cập nhật học viên có trong phiếu.
     */
    public function importSheet(Request $request, CourseClass $courseClass): RedirectResponse
    {
        abort_if((int) $courseClass->school_id !== (int) WorkingSchool::id($request), 404);
        LecturerScope::assertOwns($courseClass, $request);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:20480',
            'bo_sung' => 'nullable|boolean',
        ]);
        $boSung = $request->boolean('bo_sung');
        $file = $request->file('file');
        $original = $file->getClientOriginalName();

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'school_id' => $courseClass->school_id,
            'class_id' => $courseClass->id,
            'file_name' => $original,
            'file_path' => $file->storeAs('imports', 'class_sheet_' . time() . '_' . \Illuminate\Support\Str::random(6) . '_' . $original),
            'type' => 'class_sheet',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);
        \App\Jobs\Imports\ImportClassSheetJob::dispatch($batch, (int) $courseClass->school_id, [
            'bo_sung' => $boSung,
            'lop_id' => $courseClass->id,
        ]);

        return redirect()->back()->with('success', ($boSung ? 'Đã nhận phiếu bổ sung' : 'Đã nhận phiếu điểm')
            . " của lớp {$courseClass->class_code}. Hệ thống đang kiểm và đo — kết quả hiện ở mục Các lần nộp phiếu bên dưới.");
    }

    /**
     * Bảng 3 — bảng đo PI của học phần, xuất CSV cho giảng viên phụ trách lớp.
     * Hai khối: [I] tổng theo PI · [II] tách theo từng bài đánh giá (quá trình / cuối kỳ).
     */
    public function exportPiAttainment(
        Request $request,
        CourseClass $courseClass,
        \App\Services\OBE\CoursePiAttainmentService $service,
    ): StreamedResponse {
        abort_if((int) $courseClass->school_id !== (int) WorkingSchool::id($request), 404);
        LecturerScope::assertOwns($courseClass, $request);

        $courseClass->load('syllabus.course');
        $data = $service->forClass($courseClass);
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1) . '%';

        $rows = [
            ['BẢNG ĐO CHUẨN ĐẦU RA CỦA HỌC PHẦN'],
            ['Lớp', $courseClass->class_code],
            ['Học phần', ($courseClass->syllabus?->course?->code ?? '') . ' — ' . ($courseClass->syllabus?->course?->name ?? '')],
            ['Năm học / Học kỳ', $courseClass->academic_year . ' / ' . $courseClass->semester],
            ['Số SV có điểm trong lớp', $data['students_total']],
            ['Ngưỡng đạt (điểm PI trong học phần)', number_format($data['pass_threshold'], 1)],
            ['Mục tiêu mức đạt', number_format($data['target_percent'], 0) . '%'],
            ['Nguồn đo', $data['is_question_scored'] ? 'Điểm theo câu hỏi' : 'Điểm theo bài đánh giá'],
            [],
            ['LƯU Ý: đây là mức đạt PI TRONG học phần này. Con số này KHÔNG cộng lại thành mức đạt PI'],
            ['của cả chương trình (PI chương trình gộp nhiều môn theo tín chỉ × mức I/R/M/A).'],
            [],
            ['[I] TỔNG HỢP THEO PI'],
            ['Mã PI', 'Thuộc PLO', 'Mức I/R/M/A', 'Số HP của CTĐT đo PI này', 'CLO đo PI',
                'Số SV được đo', 'Số đạt', '% đạt', 'Điểm TB', 'Đạt mục tiêu'],
        ];

        foreach ($data['pis'] as $pi) {
            $rows[] = [
                $pi['pi_code'], $pi['plo_code'], $pi['mapping_level'],
                // Học phần này là 1 trong N — nhắc người đọc rằng cột "% đạt" chỉ là phần đóng góp
                // của một học phần, đúng như dòng LƯU Ý ở đầu file.
                $pi['program_courses'] === null ? '—' : '1/' . $pi['program_courses'],
                $pi['clo_codes'],
                $pi['students_measured'], $pi['passed'], $pct($pi['attainment_percent']),
                $pi['avg_score'] ?? '—', $pi['met_target'] ? 'Đạt' : 'Chưa đạt',
            ];
        }

        $rows[] = [];
        $rows[] = ['[II] CHI TIẾT THEO BÀI ĐÁNH GIÁ'];
        $rows[] = ['Mã PI', 'Bài đánh giá', 'Loại', 'Trọng số %', 'Số SV dự thi', 'Điểm TB', 'Số đạt', '% đạt'];

        foreach ($data['pis'] as $pi) {
            foreach ($pi['assessments'] as $a) {
                $rows[] = [
                    $pi['pi_code'], $a['assessment_name'], $a['category'], $a['weight_percent'],
                    $a['students_sat'], $a['avg_score'] ?? '—', $a['passed'], $pct($a['attainment_percent']),
                ];
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
            'Content-Disposition' => 'attachment; filename="DoPI_' . $courseClass->class_code . '_' . time() . '.csv"',
        ]);
    }

    public function importEnrollments(Request $request, CourseClass $courseClass): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $fileName = 'enrollments/enroll_' . time() . '_' . $request->file('file')->getClientOriginalName();
        $path = $request->file('file')->storeAs('enrollments', $fileName);

        $batch = FileBatch::create([
            'user_id' => Auth::id(),
            'class_id' => $courseClass->id,
            'file_name' => $request->file('file')->getClientOriginalName(),
            'file_path' => $path,
            'type' => 'enrollment',
            'status' => 'queued',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        ImportEnrollmentJob::dispatch($batch, $courseClass);

        return redirect()->back()->with('success', 'Đã tải lên danh sách sinh viên và đưa vào hàng đợi xử lý!');
    }

    public function updateEnrollmentStatus(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $request->validate([
            'status' => 'required|string|in:active,locked,suspended,completed,withdrawn',
        ]);

        try {
            $service = app(EnrollmentService::class);
            $service->updateStatus($enrollment, $request->status);
            return redirect()->back()->with('success', 'Đã cập nhật trạng thái sinh viên trong lớp.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /** @return array{classes: array<int,string>, pis: array<int,array<string,mixed>>}|null */
    private function courseRollup(CourseClass $courseClass): ?array
    {
        $courseId = $courseClass->syllabus?->course_id;
        if (! $courseId) {
            return null;
        }

        $classes = CourseClass::where('school_id', $courseClass->school_id)
            ->where('cohort_id', $courseClass->cohort_id)
            ->whereHas('syllabus', fn ($q) => $q->where('course_id', $courseId))
            ->orderBy('class_code')
            ->get();

        if ($classes->count() < 2) {
            return null;
        }

        return [
            'classes' => $classes->pluck('class_code')->all(),
            'pis' => app(\App\Services\OBE\CoursePiAttainmentService::class)->rollUp($classes),
        ];
    }
}
