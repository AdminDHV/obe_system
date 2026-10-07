<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// CỔNG TRA CỨU CÔNG KHAI — học viên nhập MSSV + mã tra cứu (in trên phiếu) để xem kết quả.
// Không xác thực, nên chặn dò quét bằng throttle; xem PublicLookupController để biết các ràng buộc.
//
// Đường dẫn chọn đủ dài để rõ nghĩa nhưng vẫn GÕ ĐƯỢC TỪ GIẤY — học viên chép tay từ phiếu in,
// đường dẫn càng dài càng dễ sai.
Route::get('/ket-qua-hoc-tap', [App\Http\Controllers\PublicLookupController::class, 'show'])
    ->name('lookup.show');
Route::post('/ket-qua-hoc-tap', [App\Http\Controllers\PublicLookupController::class, 'lookup'])
    ->middleware('throttle:10,1')
    ->name('lookup.submit');

// Phiếu đã in cho học viên mang đường dẫn CŨ — không được để chúng thành liên kết chết.
Route::redirect('/tra-cuu', '/ket-qua-hoc-tap', 301);

Route::get('/dashboard', function () {
    $user = Illuminate\Support\Facades\Auth::user();
    // Mô hình vận hành hộ (operator): mọi tài khoản vận hành (super_admin / lecturer)
    // vào thẳng console quản lý. Portal tự-phục-vụ lecturer/student đã gỡ.
    if ($user->hasRole('super_admin') || $user->hasRole('lecturer')) {
        return redirect()->route('manager.dashboard.index');
    }
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');

// ── GIẢNG VIÊN + NGƯỜI VẬN HÀNH ────────────────────────────────────────────────
// Danh sách TRẮNG hẹp: đúng những gì yêu cầu của khách cần — "GV theo dõi học phần mình dạy
// và nhập điểm". Mọi thứ khác nằm ở nhóm super_admin bên dưới (mặc định CẤM).
// Riêng phạm vi từng lớp còn bị kẹp lần nữa trong controller bằng App\Support\LecturerScope —
// route chỉ chặn "được vào trang nào", không chặn "được xem lớp của ai".
// `viewer` cũng vào được nhóm này (bảng điều khiển · hướng dẫn · lớp học phần) nhưng kèm
// EnsureReadOnlyRole nên không nhập điểm được. Khác giảng viên ở chỗ LecturerScope chỉ kẹp người
// có role `lecturer`, nên người xem thấy MỌI lớp của trường mình — đúng nghĩa cố vấn học tập.
Route::middleware(['auth', 'role:super_admin|lecturer|viewer', App\Http\Middleware\ResolveWorkingSchool::class, App\Http\Middleware\EnsureReadOnlyRole::class, App\Http\Middleware\EnsureModelBelongsToWorkingSchool::class])->prefix('manager')->name('manager.')->group(function () {
    Route::get('/dashboard', [App\Http\Controllers\Manager\DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/guide', [App\Http\Controllers\Manager\GuideController::class, 'index'])->name('guide.index');

    // Lớp học phần: xem lớp mình dạy, nhập điểm, xuất bảng đo PI của lớp.
    Route::get('/classes', [App\Http\Controllers\Manager\ClassController::class, 'index'])->name('classes.index');
    Route::get('/classes/{courseClass}', [App\Http\Controllers\Manager\ClassController::class, 'show'])->name('classes.show');
    Route::post('/classes/{courseClass}/grades/import', [App\Http\Controllers\Manager\ClassController::class, 'importGrades'])->name('classes.grades.import');
    // Giáo viên tải phiếu của lớp mình và nộp lại — khung (CLO, bài đánh giá) do kỹ thuật dựng sẵn.
    Route::get('/classes/{courseClass}/phieu', [App\Http\Controllers\Manager\ClassController::class, 'sheetTemplate'])->name('classes.sheet.template');
    Route::post('/classes/{courseClass}/phieu', [App\Http\Controllers\Manager\ClassController::class, 'importSheet'])->name('classes.sheet.import');
    Route::get('/classes/{courseClass}/export-pi', [App\Http\Controllers\Manager\ClassController::class, 'exportPiAttainment'])->name('classes.export-pi');

    // Báo cáo học phần theo mẫu của trường: đo theo HỌC PHẦN, gộp mọi lớp trong một học kỳ.
    // Nằm ở nhóm này vì người ký báo cáo là giảng viên phụ trách; `LecturerScope` kẹp lại đúng lớp
    // họ dạy. Đường dẫn '/reports/hoc-phan' phải khai ở đây, TRƯỚC '/reports/{snapshot}' của nhóm
    // dưới, không thì bị nuốt thành mã snapshot.
    Route::get('/reports/hoc-phan', [App\Http\Controllers\Manager\CourseReportController::class, 'index'])->name('reports.course.index');
    Route::get('/reports/hoc-phan/{course}', [App\Http\Controllers\Manager\CourseReportController::class, 'show'])->name('reports.course.show');
    Route::get('/reports/hoc-phan/{course}/word', [App\Http\Controllers\Manager\CourseReportController::class, 'download'])->name('reports.course.word');

    // Bảng điểm cho phần mềm quản lý đào tạo: tải file của phần mềm đó lên, hệ thống điền điểm vào.
    Route::get('/classes/{courseClass}/bang-diem-psc', [App\Http\Controllers\Manager\PscGradeSheetController::class, 'form'])->name('classes.psc.form');
    Route::post('/classes/{courseClass}/bang-diem-psc', [App\Http\Controllers\Manager\PscGradeSheetController::class, 'inspect'])->name('classes.psc.inspect');
    Route::get('/classes/{courseClass}/bang-diem-psc/dien', [App\Http\Controllers\Manager\PscGradeSheetController::class, 'fill'])->name('classes.psc.fill');
});

// ── CHỈ NGƯỜI VẬN HÀNH (super_admin) ───────────────────────────────────────────
// ── NGƯỜI VẬN HÀNH (super_admin) + NGƯỜI XEM (viewer, chỉ đọc) ─────────────────
// `viewer` = cố vấn học tập / ban giám hiệu / đảm bảo chất lượng: xem TOÀN TRƯỜNG của mình nhưng
// KHÔNG ghi được gì. Chặn ghi bằng EnsureReadOnlyRole (chặn theo method) chứ không liệt kê route,
// để route thêm sau này cũng tự động an toàn.
// Giảng viên KHÔNG ở nhóm này — họ chỉ có nhóm hẹp bên trên, đúng phạm vi lớp mình dạy.
Route::middleware(['auth', 'role:super_admin|viewer', App\Http\Middleware\ResolveWorkingSchool::class, App\Http\Middleware\EnsureReadOnlyRole::class, App\Http\Middleware\EnsureModelBelongsToWorkingSchool::class])->prefix('manager')->name('manager.')->group(function () {

    // Cohorts (CRUD)
    Route::get('/cohorts', [App\Http\Controllers\Manager\CohortController::class, 'index'])->name('cohorts.index');
    Route::post('/cohorts', [App\Http\Controllers\Manager\CohortController::class, 'store'])->name('cohorts.store');
    Route::put('/cohorts/{cohort}', [App\Http\Controllers\Manager\CohortController::class, 'update'])->name('cohorts.update');
    Route::delete('/cohorts/{cohort}', [App\Http\Controllers\Manager\CohortController::class, 'destroy'])->name('cohorts.destroy');

    // Courses (CRUD)
    Route::get('/courses', [App\Http\Controllers\Manager\CourseController::class, 'index'])->name('courses.index');
    Route::post('/courses', [App\Http\Controllers\Manager\CourseController::class, 'store'])->name('courses.store');
    Route::put('/courses/{course}', [App\Http\Controllers\Manager\CourseController::class, 'update'])->name('courses.update');
    Route::delete('/courses/{course}', [App\Http\Controllers\Manager\CourseController::class, 'destroy'])->name('courses.destroy');

    // Syllabi (CRUD)
    Route::get('/syllabi', [App\Http\Controllers\Manager\SyllabusController::class, 'index'])->name('syllabi.index');
    Route::post('/syllabi', [App\Http\Controllers\Manager\SyllabusController::class, 'store'])->name('syllabi.store');
    Route::put('/syllabi/{syllabus}', [App\Http\Controllers\Manager\SyllabusController::class, 'update'])->name('syllabi.update');
    Route::delete('/syllabi/{syllabus}', [App\Http\Controllers\Manager\SyllabusController::class, 'destroy'])->name('syllabi.destroy');

    // Assessments (CRUD)
    Route::get('/assessments', [App\Http\Controllers\Manager\AssessmentController::class, 'index'])->name('assessments.index');
    Route::post('/assessments', [App\Http\Controllers\Manager\AssessmentController::class, 'store'])->name('assessments.store');
    Route::get('/assessments/{assessment}', [App\Http\Controllers\Manager\AssessmentController::class, 'show'])->name('assessments.show');
    Route::put('/assessments/{assessment}', [App\Http\Controllers\Manager\AssessmentController::class, 'update'])->name('assessments.update');
    Route::delete('/assessments/{assessment}', [App\Http\Controllers\Manager\AssessmentController::class, 'destroy'])->name('assessments.destroy');
    // Map Thành phần đánh giá → CLO
    Route::post('/assessment-clo-mappings', [App\Http\Controllers\Manager\AssessmentCloMappingController::class, 'store'])->name('assessment-clo-mappings.store');
    Route::put('/assessment-clo-mappings/{mapping}', [App\Http\Controllers\Manager\AssessmentCloMappingController::class, 'update'])->name('assessment-clo-mappings.update');
    Route::delete('/assessment-clo-mappings/{mapping}', [App\Http\Controllers\Manager\AssessmentCloMappingController::class, 'destroy'])->name('assessment-clo-mappings.destroy');

    // Rubrics (CRUD) + nested Criteria
    Route::get('/rubrics', [App\Http\Controllers\Manager\RubricController::class, 'index'])->name('rubrics.index');
    Route::post('/rubrics', [App\Http\Controllers\Manager\RubricController::class, 'store'])->name('rubrics.store');
    Route::get('/rubrics/{rubric}', [App\Http\Controllers\Manager\RubricController::class, 'show'])->name('rubrics.show');
    Route::put('/rubrics/{rubric}', [App\Http\Controllers\Manager\RubricController::class, 'update'])->name('rubrics.update');
    Route::delete('/rubrics/{rubric}', [App\Http\Controllers\Manager\RubricController::class, 'destroy'])->name('rubrics.destroy');
    Route::post('/rubric-criteria', [App\Http\Controllers\Manager\RubricCriteriaController::class, 'store'])->name('rubric-criteria.store');
    Route::put('/rubric-criteria/{criteria}', [App\Http\Controllers\Manager\RubricCriteriaController::class, 'update'])->name('rubric-criteria.update');
    Route::delete('/rubric-criteria/{criteria}', [App\Http\Controllers\Manager\RubricCriteriaController::class, 'destroy'])->name('rubric-criteria.destroy');

    // Lecturers (CRUD)
    Route::get('/lecturers', [App\Http\Controllers\Manager\LecturerController::class, 'index'])->name('lecturers.index');
    Route::get('/lecturers/{user}', [App\Http\Controllers\Manager\LecturerController::class, 'show'])->name('lecturers.show');
    Route::post('/lecturers', [App\Http\Controllers\Manager\LecturerController::class, 'store'])->name('lecturers.store');
    Route::put('/lecturers/{user}', [App\Http\Controllers\Manager\LecturerController::class, 'update'])->name('lecturers.update');
    Route::delete('/lecturers/{user}', [App\Http\Controllers\Manager\LecturerController::class, 'destroy'])->name('lecturers.destroy');

    // Trường / người dùng / nhật ký — CHỈ super_admin, người xem KHÔNG được vào.
    // Đây từng là khối `{}` trần chỉ để gom cho dễ đọc, không phải ràng buộc. Nay nhóm ngoài đã
    // mở cho `viewer` nên khối này phải thành nhóm THẬT, nếu không người xem thấy luôn danh sách
    // người dùng và nhật ký hệ thống.
    Route::middleware('role:super_admin')->group(function () {
        // Trường (tenant) — quản lý đa-trường
        Route::get('/schools', [App\Http\Controllers\Manager\SchoolController::class, 'index'])->name('schools.index');
        Route::post('/schools', [App\Http\Controllers\Manager\SchoolController::class, 'store'])->name('schools.store');
        Route::put('/schools/{school}', [App\Http\Controllers\Manager\SchoolController::class, 'update'])->name('schools.update');
        Route::delete('/schools/{school}', [App\Http\Controllers\Manager\SchoolController::class, 'destroy'])->name('schools.destroy');
        Route::put('/schools/{school}/obe-settings', [App\Http\Controllers\Manager\SchoolController::class, 'updateObeSettings'])->name('schools.obe-settings');

        Route::get('/users', [App\Http\Controllers\Manager\UserController::class, 'index'])->name('users.index');
        Route::post('/users', [App\Http\Controllers\Manager\UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [App\Http\Controllers\Manager\UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/toggle-active', [App\Http\Controllers\Manager\UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::delete('/users/{user}', [App\Http\Controllers\Manager\UserController::class, 'destroy'])->name('users.destroy');

        // Audit log viewer
        Route::get('/audit-logs', [App\Http\Controllers\Manager\AuditLogController::class, 'index'])->name('audit-logs.index');
        // Nhật ký import + đo lường (phân trang + lọc)
        Route::get('/import-batches', [App\Http\Controllers\Manager\ImportBatchController::class, 'index'])->name('import-batches.index');
        Route::get('/measurement-runs', [App\Http\Controllers\Manager\MeasurementRunController::class, 'index'])->name('measurement-runs.index');
    });

    // Departments (CRUD)
    Route::get('/departments', [App\Http\Controllers\Manager\DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [App\Http\Controllers\Manager\DepartmentController::class, 'store'])->name('departments.store');
    Route::put('/departments/{department}', [App\Http\Controllers\Manager\DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('/departments/{department}', [App\Http\Controllers\Manager\DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::get('/departments/{department}', [App\Http\Controllers\Manager\DepartmentController::class, 'show'])->name('departments.show');

    // Programs (CRUD)
    Route::get('/programs', [App\Http\Controllers\Manager\ProgramController::class, 'index'])->name('programs.index');
    Route::post('/programs', [App\Http\Controllers\Manager\ProgramController::class, 'store'])->name('programs.store');
    Route::put('/programs/{program}', [App\Http\Controllers\Manager\ProgramController::class, 'update'])->name('programs.update');
    Route::delete('/programs/{program}', [App\Http\Controllers\Manager\ProgramController::class, 'destroy'])->name('programs.destroy');
    Route::get('/programs/{program}', [App\Http\Controllers\Manager\ProgramController::class, 'show'])->name('programs.show');

    // Khối học phần trong CTĐT (bắt buộc / hướng chuyên ngành) — đầu vào của độ phủ PI
    Route::get('/curriculum', [App\Http\Controllers\Manager\CurriculumGroupController::class, 'index'])->name('curriculum.index');
    Route::post('/curriculum', [App\Http\Controllers\Manager\CurriculumGroupController::class, 'store'])->name('curriculum.store');
    Route::put('/curriculum/{curriculumGroup}', [App\Http\Controllers\Manager\CurriculumGroupController::class, 'update'])->name('curriculum.update');
    Route::delete('/curriculum/{curriculumGroup}', [App\Http\Controllers\Manager\CurriculumGroupController::class, 'destroy'])->name('curriculum.destroy');
    Route::post('/curriculum/assign-courses', [App\Http\Controllers\Manager\CurriculumGroupController::class, 'assignCourses'])->name('curriculum.assign-courses');
    Route::post('/curriculum/assign-students', [App\Http\Controllers\Manager\CurriculumGroupController::class, 'assignStudents'])->name('curriculum.assign-students');
    Route::post('/curriculum/infer-tracks', [App\Http\Controllers\Manager\CurriculumGroupController::class, 'inferTracks'])->name('curriculum.infer-tracks');

    // Classes
    Route::post('/classes', [App\Http\Controllers\Manager\ClassController::class, 'store'])->name('classes.store');
    Route::put('/classes/{courseClass}', [App\Http\Controllers\Manager\ClassController::class, 'update'])->name('classes.update');
    Route::delete('/classes/{courseClass}', [App\Http\Controllers\Manager\ClassController::class, 'destroy'])->name('classes.destroy');
    Route::post('/classes/{courseClass}/enrollments/import', [App\Http\Controllers\Manager\ClassController::class, 'importEnrollments'])->name('classes.enrollments.import');
    Route::post('/enrollments/{enrollment}/status', [App\Http\Controllers\Manager\ClassController::class, 'updateEnrollmentStatus'])->name('enrollments.status');

    // Students (CRUD)
    Route::get('/students', [App\Http\Controllers\Manager\StudentController::class, 'index'])->name('students.index');
    Route::post('/students', [App\Http\Controllers\Manager\StudentController::class, 'store'])->name('students.store');
    // ĐẶT TRƯỚC /students/{student} — nếu không, 'export-plo-bulk' sẽ bị bắt làm uuid sinh viên.
    // Phiếu cá nhân + gói zip CHỨA MÃ TRA CỨU riêng của từng học viên — ai cầm mã là xem được kết
    // quả của người đó. Chỉ người vận hành được xuất; người xem chỉ có bảng tổng lớp (không có mã).
    Route::get('/students/export-plo-bulk', [App\Http\Controllers\Manager\StudentController::class, 'exportPloBulk'])->middleware('role:super_admin')->name('students.export-plo-bulk');
    Route::get('/students/export-class-summary', [App\Http\Controllers\Manager\StudentController::class, 'exportClassSummary'])->name('students.export-class-summary');
    Route::get('/students/{student}', [App\Http\Controllers\Manager\StudentController::class, 'show'])->name('students.show');
    Route::put('/students/{student}', [App\Http\Controllers\Manager\StudentController::class, 'update'])->name('students.update');
    Route::delete('/students/{student}', [App\Http\Controllers\Manager\StudentController::class, 'destroy'])->name('students.destroy');
    Route::get('/students/{student}/export-plo', [App\Http\Controllers\Manager\StudentController::class, 'exportPlo'])->middleware('role:super_admin')->name('students.export-plo');
    Route::put('/students/{student}/lookup-code', [App\Http\Controllers\Manager\StudentController::class, 'regenerateLookupCode'])->name('students.lookup-code');

    // OBE
    Route::get('/obe', [App\Http\Controllers\ObeController::class, 'index'])->name('obe.index');
    Route::post('/obe/plos', [App\Http\Controllers\ObeController::class, 'storePlo'])->name('obe.plos.store');
    Route::put('/obe/plos/{plo}', [App\Http\Controllers\ObeController::class, 'updatePlo'])->name('obe.plos.update');
    Route::delete('/obe/plos/{plo}', [App\Http\Controllers\ObeController::class, 'destroyPlo'])->name('obe.plos.destroy');
    Route::post('/obe/pis', [App\Http\Controllers\ObeController::class, 'storePi'])->name('obe.pis.store');
    Route::put('/obe/pis/{pi}', [App\Http\Controllers\ObeController::class, 'updatePi'])->name('obe.pis.update');
    Route::delete('/obe/pis/{pi}', [App\Http\Controllers\ObeController::class, 'destroyPi'])->name('obe.pis.destroy');
    Route::post('/obe/clos', [App\Http\Controllers\ObeController::class, 'storeClo'])->name('obe.clos.store');
    Route::put('/obe/clos/{clo}', [App\Http\Controllers\ObeController::class, 'updateClo'])->name('obe.clos.update');
    Route::delete('/obe/clos/{clo}', [App\Http\Controllers\ObeController::class, 'destroyClo'])->name('obe.clos.destroy');
    Route::post('/obe/mappings', [App\Http\Controllers\ObeController::class, 'storeMapping'])->name('obe.mappings.store');
    Route::put('/obe/mappings/{mapping}', [App\Http\Controllers\ObeController::class, 'updateMapping'])->name('obe.mappings.update');
    Route::delete('/obe/mappings/{mapping}', [App\Http\Controllers\ObeController::class, 'destroyMapping'])->name('obe.mappings.destroy');

    // Reports (AUN-QA)
    // Bộ file mẫu — một trang gom mọi file mẫu, để trường mới không phải mò từng màn hình.
    Route::get('/file-mau', fn () => \Inertia\Inertia::render('Manager/Templates/Index'))->name('templates.index');
    // Mẫu ghi danh là mẫu TĨNH (chỉ một cột MSSV). Route cũ đòi {courseClass} nhưng hàm không hề
    // dùng tới — đã gỡ, vì tham số không dùng chỉ khiến người sau tưởng file có dữ liệu của lớp.

    Route::get('/reports', [App\Http\Controllers\Manager\ReportController::class, 'index'])->name('reports.index');
    Route::post('/reports', [App\Http\Controllers\Manager\ReportController::class, 'generate'])->name('reports.generate');
    Route::post('/reports/recalculate', [App\Http\Controllers\Manager\ReportController::class, 'recalculate'])->name('reports.recalculate');
    Route::get('/reports/readiness', [App\Http\Controllers\Manager\ReportController::class, 'readiness'])->name('reports.readiness');
    Route::get('/reports/targets', [App\Http\Controllers\Manager\TargetController::class, 'index'])->name('reports.targets.index');
    Route::post('/reports/targets', [App\Http\Controllers\Manager\TargetController::class, 'store'])->name('reports.targets.store');
    Route::get('/reports/cqi', [App\Http\Controllers\Manager\CqiController::class, 'index'])->name('reports.cqi.index');
    Route::post('/reports/cqi/seed', [App\Http\Controllers\Manager\CqiController::class, 'seed'])->name('reports.cqi.seed');
    Route::patch('/reports/cqi/{cqiAction}', [App\Http\Controllers\Manager\CqiController::class, 'update'])->name('reports.cqi.update');
    Route::post('/reports/structure', [App\Http\Controllers\Manager\ReportController::class, 'importStructure'])->name('reports.structure');
    Route::get('/reports/structure/template', [App\Http\Controllers\Manager\ReportController::class, 'structureTemplate'])->name('reports.structure.template');
    // Phiếu lớp học phần — file thứ hai (và cuối cùng) trường cần điền.
    Route::post('/reports/class-sheets', [App\Http\Controllers\Manager\ReportController::class, 'importClassSheets'])->name('reports.class-sheets');
    Route::get('/reports/class-sheets/template', [App\Http\Controllers\Manager\ReportController::class, 'classSheetTemplate'])->name('reports.class-sheets.template');
    // Khung học phần theo đợt — kỹ thuật của trường dựng một lần cho cả khóa (CLO, bài đánh giá, lớp).
    Route::post('/reports/course-frame', [App\Http\Controllers\Manager\ReportController::class, 'importCourseFrame'])->name('reports.course-frame');
    Route::get('/reports/course-frame/template', [App\Http\Controllers\Manager\ReportController::class, 'courseFrameTemplate'])->name('reports.course-frame.template');
    Route::post('/reports/bulk-grades', [App\Http\Controllers\Manager\ReportController::class, 'bulkImportGrades'])->name('reports.bulk-grades');
    Route::post('/reports/legacy-grades', [App\Http\Controllers\Manager\ReportController::class, 'importLegacyGrades'])->name('reports.legacy-grades');
    Route::post('/reports/aggregate-grades', [App\Http\Controllers\Manager\ReportController::class, 'importAggregateGrades'])->name('reports.aggregate-grades');
    Route::post('/reports/question-grades', [App\Http\Controllers\Manager\ReportController::class, 'importQuestionGrades'])->name('reports.question-grades');
    Route::post('/reports/survey', [App\Http\Controllers\Manager\ReportController::class, 'importSurvey'])->name('reports.survey');
    Route::get('/reports/survey/template', [App\Http\Controllers\Manager\ReportController::class, 'surveyTemplate'])->name('reports.survey.template');
    Route::post('/reports/student-roster', [App\Http\Controllers\Manager\ReportController::class, 'importStudentRoster'])->name('reports.student-roster');
    Route::get('/reports/export-excel', [App\Http\Controllers\Manager\ReportController::class, 'exportExcel'])->name('reports.export-excel');
    // Dữ liệu mẫu chỉ dành cho super_admin (tránh operator lỡ tạo/xoá trong dữ liệu thật).
    Route::post('/reports/demo/load', [App\Http\Controllers\Manager\ReportController::class, 'loadDemo'])->name('reports.demo.load');
    Route::post('/reports/demo/clear', [App\Http\Controllers\Manager\ReportController::class, 'clearDemo'])->name('reports.demo.clear');
    Route::get('/reports/{snapshot}/download', [App\Http\Controllers\Manager\ReportController::class, 'download'])->name('reports.download');
    Route::get('/reports/batches/{batch}/file', [App\Http\Controllers\Manager\ReportController::class, 'downloadBatchFile'])->name('reports.batch-file');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ── Portal tự-phục-vụ Giảng viên & Sinh viên: ĐÃ GỠ (mô hình vận hành hộ) ──
// Operator nhập điểm/khung từ file của trường rồi đo & xuất báo cáo; GV/SV không
// đăng nhập thao tác. Các group route `lecturer.*` / `student.*` + grading grid
// đã gỡ ở Sprint 35 (B1). Khôi phục qua git history nếu chuyển hướng SaaS.
// Controller/Pages tương ứng còn trên đĩa (xoá ở B2 nếu chốt operator).

require __DIR__.'/auth.php';
