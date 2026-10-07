<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Jobs\Calculations\GenerateReportJob;
use App\Jobs\Imports\ImportGradeJob;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\FileBatch;
use App\Models\MeasurementRun;
use App\Models\Program;
use App\Models\ReportSnapshot;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $semester = $request->get('semester');
        $academicYear = $request->get('academic_year');
        $programId = $request->get('program_id');
        $cohortId = $request->get('cohort_id');

        // Ngữ cảnh ĐA-TRƯỜNG: chỉ tin "Trường đang làm việc" đã kẹp per-user (KHÔNG fallback về
        // trường đầu — tránh lộ dữ liệu cho user bị khoá nhưng chưa gán trường → null = rỗng).
        $schoolId = \App\Support\WorkingSchool::id($request);

        $programs = Program::where('school_id', $schoolId)->get();
        $cohorts = Cohort::where('school_id', $schoolId)->get();

        $classQuery = CourseClass::query()
            ->with(['syllabus.course', 'lecturer', 'cohort'])
            ->where('school_id', $schoolId)
            ->when($semester, fn($q) => $q->where('semester', $semester))
            ->when($academicYear, fn($q) => $q->where('academic_year', $academicYear))
            ->when($cohortId, fn($q) => $q->where('cohort_id', $cohortId))
            ->when($programId, fn($q) => $q->whereHas('syllabus.course.programs', fn($sq) => $sq->where('programs.id', $programId)));

        // Giảng viên: bảng điều khiển phải nói về LỚP MÌNH DẠY, không phải toàn trường.
        \App\Support\LecturerScope::classes($classQuery, $request);

        $classes = $classQuery->get();
        $allClasses = $classQuery->with(['syllabus.course'])->get();
        $classIds = $classes->pluck('id');

        // --- Stats (scope theo trường đang chọn) ---
        // Scope VÔ ĐIỀU KIỆN theo trường đang làm việc (null → is null → rỗng): KHÔNG bao giờ
        // rơi về toàn cục, tránh lộ dữ liệu chéo cho user bị khoá chưa gán trường.
        $scopeSchool = fn($q) => $q->where('school_id', $schoolId);
        $totalStudents = $scopeSchool(Student::query())->count();
        $totalLecturers = $scopeSchool(User::whereHas('roles', fn($q) => $q->where('name', 'lecturer')))->count();
        $totalDepartments = $scopeSchool(Department::query())->count();
        $totalPrograms = $programs->count();
        $totalClasses = $classes->count();
        $totalCohorts = $cohorts->count();

        $gradesQuery = StudentGrade::whereIn('class_id', $classIds);
        $avgScore = (clone $gradesQuery)->avg('score');
        $totalGradedStudents = (clone $gradesQuery)->distinct('student_id')->count('student_id');

        $atRiskIds = (clone $gradesQuery)
            ->select('student_id')
            ->selectRaw('AVG(score) as avg_score')
            ->groupBy('student_id')
            ->havingRaw('AVG(score) < 5')
            ->pluck('student_id');
        $atRiskCount = $atRiskIds->count();

        // --- Grade distribution ---
        $distribution = [
            ['range' => '0 - 3.9', 'count' => (clone $gradesQuery)->where('score', '<', 4)->count()],
            ['range' => '4.0 - 5.4', 'count' => (clone $gradesQuery)->whereBetween('score', [4, 5.49])->count()],
            ['range' => '5.5 - 6.9', 'count' => (clone $gradesQuery)->whereBetween('score', [5.5, 6.99])->count()],
            ['range' => '7.0 - 8.4', 'count' => (clone $gradesQuery)->whereBetween('score', [7, 8.49])->count()],
            ['range' => '8.5 - 10', 'count' => (clone $gradesQuery)->where('score', '>=', 8.5)->count()],
        ];

        // --- Per-class stats ---
        $classStats = $classes->map(function ($class) {
            $enrollmentsCount = $class->enrollments()->count();
            $grades = StudentGrade::where('class_id', $class->id);
            $gradeCount = (clone $grades)->count();
            $avgScore = (clone $grades)->avg('score');
            $expectedAssessments = $class->syllabus?->assessments()->count() ?? 0;
            $expectedGrades = $enrollmentsCount * $expectedAssessments;
            $completionRate = $expectedGrades > 0 ? round(($gradeCount / $expectedGrades) * 100, 1) : 0;

            return [
                'uuid' => $class->uuid,
                'class_code' => $class->class_code,
                'course_name' => $class->syllabus?->course?->name ?? '',
                'course_code' => $class->syllabus?->course?->code ?? '',
                'lecturer_name' => $class->lecturer?->full_name ?? '',
                'student_count' => $enrollmentsCount,
                'avg_score' => $avgScore !== null ? round((float) $avgScore, 2) : null,
                'completion_rate' => min($completionRate, 100),
            ];
        })->sortByDesc('completion_rate')->values();

        $ungradedClassCount = $classStats->where('completion_rate', 0)->count();

        // --- Lecturer workload ---
        $lecturerWorkload = User::whereHas('roles', fn($q) => $q->where('name', 'lecturer'))
            ->whereHas('courseClasses', fn($q) => $q->whereIn('id', $classIds))
            ->withCount(['courseClasses' => fn($q) => $q->whereIn('id', $classIds)])
            ->get()
            ->map(function ($lecturer) use ($classIds) {
                $lecturerClassIds = $lecturer->courseClasses()->whereIn('id', $classIds)->pluck('id');
                $totalStudents = Enrollment::whereIn('class_id', $lecturerClassIds)->distinct('student_id')->count('student_id');
                $avgScore = StudentGrade::whereIn('class_id', $lecturerClassIds)->avg('score');
                return [
                    'uuid' => $lecturer->uuid,
                    'staff_code' => $lecturer->staff_code,
                    'full_name' => $lecturer->full_name,
                    'position' => $lecturer->position,
                    'class_count' => $lecturer->course_classes_count,
                    'total_students' => $totalStudents,
                    'avg_score' => $avgScore !== null ? round((float) $avgScore, 2) : null,
                ];
            })
            ->sortByDesc('class_count')
            ->take(10)
            ->values();

        // --- PLO averages ---
        $ploAverages = [];
        if ($programId && $cohortId) {
            $ploAverages = DB::table('student_plo_results')
                ->join('students', 'student_plo_results.student_id', '=', 'students.id')
                ->join('plos', 'student_plo_results.plo_id', '=', 'plos.id')
                ->where('students.school_id', $schoolId)
                ->where('students.program_id', $programId)
                ->where('students.cohort_id', $cohortId)
                ->select('plos.code', 'plos.description as name', DB::raw('ROUND(AVG(student_plo_results.achieved_score), 2) as average_score'))
                ->groupBy('plos.code', 'plos.description', 'student_plo_results.plo_id')
                ->orderBy('plos.code')
                ->get()
                ->map(fn($item) => ['code' => $item->code, 'name' => $item->name, 'score' => (float) $item->average_score]);
        }

        // --- CLO averages ---
        $cloAverages = StudentCloResult::whereIn('class_id', $classIds)
            ->join('clos', 'student_clo_results.clo_id', '=', 'clos.id')
            ->selectRaw('clos.code, clos.description, AVG(student_clo_results.achieved_score) as avg_score')
            ->groupBy('clos.code', 'clos.description', 'clos.id')
            ->orderBy('clos.code')
            ->get()
            ->map(fn($r) => ['code' => $r->code, 'name' => $r->description, 'avg_score' => round((float) $r->avg_score, 2)])
            ->take(15);

        // --- Trend data ---
        $trendData = DB::table('course_classes')
            ->join('student_grades', 'course_classes.id', '=', 'student_grades.class_id')
            ->where('course_classes.school_id', $schoolId)
            ->selectRaw('course_classes.semester, course_classes.academic_year, AVG(student_grades.score) as avg_score')
            ->groupBy('course_classes.semester', 'course_classes.academic_year')
            ->orderBy('course_classes.academic_year')
            ->orderBy('course_classes.semester')
            ->get()
            ->map(fn($r) => [
                'semester' => "HK{$r->semester}\n{$r->academic_year}",
                'avg_score' => round((float) $r->avg_score, 2),
            ]);

        // --- Filter options ---
        $semesterOptions = CourseClass::where('school_id', $schoolId)->distinct()->pluck('semester')->sort()->values();
        $yearOptions = CourseClass::where('school_id', $schoolId)->distinct()->pluck('academic_year')->sort()->reverse()->values();

        // --- Tiến trình đo lường (4 bước) + việc cần xử lý — theo trường ---
        $schoolClassIds = $schoolId ? CourseClass::where('school_id', $schoolId)->pluck('id') : collect();
        $classesGraded = StudentGrade::whereIn('class_id', $schoolClassIds)->distinct('class_id')->count('class_id');
        $classesTotalForSchool = $schoolClassIds->count();
        $classesUngraded = max($classesTotalForSchool - $classesGraded, 0);
        $courseCount = $scopeSchool(Course::query())->count();
        $lastRun = MeasurementRun::where('school_id', $schoolId)->latest('id')->first();
        $reportsTotal = ReportSnapshot::where('school_id', $schoolId)->count();
        // ReportSnapshot dùng status 'generated' khi xong (GenerateReportJob), KHÔNG phải 'completed'.
        $reportsCompleted = ReportSnapshot::where('school_id', $schoolId)->where('status', 'generated')->count();

        $workflow = [
            'structure' => ['programs' => $totalPrograms, 'courses' => $courseCount],
            'grades' => ['classes' => $classesTotalForSchool, 'graded' => $classesGraded, 'ungraded' => $classesUngraded],
            'measurement' => ['last_run_at' => $lastRun?->created_at?->format('d/m/Y H:i'), 'source' => $lastRun?->source],
            'reports' => ['total' => $reportsTotal, 'completed' => $reportsCompleted],
        ];

        $stats = [
            'total_students' => $totalStudents,
            'total_lecturers' => $totalLecturers,
            'total_departments' => $totalDepartments,
            'total_programs' => $totalPrograms,
            'total_classes' => $totalClasses,
            'total_cohorts' => $totalCohorts,
            'avg_score' => $avgScore !== null ? round((float) $avgScore, 2) : null,
            'at_risk_count' => $atRiskCount,
            'total_graded_students' => $totalGradedStudents,
            'ungraded_class_count' => $ungradedClassCount,
        ];

        return Inertia::render('Manager/Dashboard/Index', [
            'stats' => $stats,
            'workflow' => $workflow,
            'gradeDistribution' => $distribution,
            'ploAverages' => $ploAverages,
            'cloAverages' => $cloAverages,
            'trendData' => $trendData,
            'classTableStats' => $classStats->take(10),
            'lecturerWorkload' => $lecturerWorkload,
            'programs' => $programs,
            'cohorts' => $cohorts,
            'semesterOptions' => $semesterOptions,
            'yearOptions' => $yearOptions,
            'currentFilters' => [
                'school_id' => $schoolId ? (int) $schoolId : null,
                'semester' => $semester,
                'academic_year' => $academicYear,
                'program_id' => $programId ? (int) $programId : null,
                'cohort_id' => $cohortId ? (int) $cohortId : null,
            ],
        ]);
    }
}
