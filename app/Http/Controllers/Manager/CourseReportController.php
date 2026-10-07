<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Exports\CourseReportDocx;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\School;
use App\Services\OBE\CourseReportService;
use App\Support\LecturerScope;
use App\Support\WorkingSchool;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * BÁO CÁO HỌC PHẦN — đo theo HỌC PHẦN, gộp mọi lớp trong cùng học kỳ.
 *
 * Màn hình đo sẵn có nhìn theo từng lớp; khách yêu cầu thêm góc nhìn học phần vì báo cáo nộp về
 * khoa là báo cáo của học phần, "học phần có 1, 3, 5, 10 lớp thì cũng chỉ quan tâm học phần thôi".
 */
class CourseReportController extends Controller
{
    public function index(Request $request): Response
    {
        $schoolId = WorkingSchool::id($request);
        $allowed = LecturerScope::allowedClassIds($request);

        $classes = CourseClass::query()
            ->where('school_id', $schoolId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->get(['id', 'syllabus_id', 'academic_year', 'semester']);

        $courseIdBySyllabus = CourseSyllabus::whereIn('id', $classes->pluck('syllabus_id')->unique())
            ->pluck('course_id', 'id');

        $byCourse = [];
        foreach ($classes as $class) {
            $courseId = $courseIdBySyllabus[$class->syllabus_id] ?? null;
            if ($courseId === null) {
                continue;
            }
            $key = ($class->academic_year ?? '') . '|' . ($class->semester ?? '');
            $byCourse[$courseId][$key] ??= [
                'nam_hoc' => $class->academic_year,
                'hoc_ky' => $class->semester === null ? null : (int) $class->semester,
                'so_lop' => 0,
            ];
            $byCourse[$courseId][$key]['so_lop']++;
        }

        $courses = Course::whereIn('id', array_keys($byCourse))
            ->when($q = $request->get('q'), fn ($query, $search) => $query->where(
                fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"),
            ))
            ->orderBy('code')
            ->get(['id', 'uuid', 'code', 'name', 'credits', 'periods', 'managing_unit'])
            ->map(function (Course $course) use ($byCourse) {
                $terms = collect($byCourse[$course->id] ?? [])
                    ->sortByDesc(fn ($t) => ($t['nam_hoc'] ?? '') . str_pad((string) ($t['hoc_ky'] ?? 0), 2, '0', STR_PAD_LEFT))
                    ->values()->all();

                return [
                    'uuid' => $course->uuid,
                    'code' => $course->code,
                    'name' => $course->name,
                    'credits' => $course->credits,
                    'periods' => $course->periods,
                    'managing_unit' => $course->managing_unit,
                    'terms' => $terms,
                ];
            });

        return Inertia::render('Manager/CourseReports/Index', [
            'courses' => $courses,
            'currentFilters' => ['q' => $q],
        ]);
    }

    public function show(Request $request, Course $course): Response
    {
        $data = $this->report($request, $course);

        return Inertia::render('Manager/CourseReports/Show', [
            'course' => [
                'uuid' => $course->uuid,
                'code' => $course->code,
                'name' => $course->name,
            ],
            'filters' => [
                'nam_hoc' => $request->get('nam_hoc'),
                'hoc_ky' => $request->get('hoc_ky') === null ? null : (int) $request->get('hoc_ky'),
            ],
            'terms' => app(CourseReportService::class)->availableTerms($course, LecturerScope::allowedClassIds($request)),
            'report' => $data,
        ]);
    }

    public function download(Request $request, Course $course): BinaryFileResponse
    {
        $data = $this->report($request, $course);
        abort_if($data === null, 404, 'Học phần chưa có lớp nào trong học kỳ đã chọn.');

        $school = School::find($course->school_id);
        $docx = new CourseReportDocx($data, $school?->name ?: 'TRƯỜNG ĐẠI HỌC HÙNG VƯƠNG');

        $path = storage_path('app/tmp/' . uniqid('bao-cao-hoc-phan-', true) . '.docx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $docx->save($path);

        return response()->download($path, $docx->fileName())->deleteFileAfterSend();
    }

    /** @return array<string,mixed>|null */
    private function report(Request $request, Course $course): ?array
    {
        abort_if((int) $course->school_id !== (int) WorkingSchool::id($request), 404);

        $namHoc = $request->get('nam_hoc') ?: null;
        $hocKy = $request->get('hoc_ky');

        return app(CourseReportService::class)->build(
            $course,
            $namHoc === null ? null : (string) $namHoc,
            $hocKy === null || $hocKy === '' ? null : (int) $hocKy,
            LecturerScope::allowedClassIds($request),
        );
    }
}
