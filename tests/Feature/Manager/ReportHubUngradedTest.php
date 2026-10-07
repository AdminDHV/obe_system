<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Assessment;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Hub đo lường liệt kê đúng "lớp chưa nhập điểm" (việc cụ thể của todo);
 * lớp đã có ≥1 điểm thì bị loại.
 */
class ReportHubUngradedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'lecturer', 'student'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_ungraded_classes_listed_then_excluded_after_grade(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');

        $school = School::create(['code' => 'SCH-U', 'name' => 'Ungraded']);
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $course = Course::create(['code' => 'C1', 'name' => 'Môn 1', 'credits' => 3, 'school_id' => $school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => $admin->id,
            'class_code' => 'C1-CLS', 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
            'school_id' => $school->id,
        ]);

        // Chưa có điểm → có trong danh sách. Chọn trường đang làm việc = trường của lớp.
        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $school->id)
            ->get(route('manager.reports.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->component('Manager/Reports/Index')
                ->has('ungradedClasses', 1)
                ->where('ungradedClasses.0.class_code', 'C1-CLS'));

        // Thêm 1 điểm cho lớp → bị loại khỏi danh sách.
        $student = Student::create([
            'student_code' => 'SV1', 'full_name' => 'A', 'school_id' => $school->id,
            'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
        ]);
        $assessment = Assessment::create([
            'syllabus_id' => $syllabus->id, 'name' => 'Điểm tổng kết',
            'assessment_category' => 'final', 'weight_percent' => 100, 'is_rubric_based' => false,
        ]);
        StudentGrade::create([
            'student_id' => $student->id, 'class_id' => $class->id,
            'assessment_id' => $assessment->id, 'score' => 7.5,
        ]);

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $school->id)
            ->get(route('manager.reports.index'))
            ->assertInertia(fn (Assert $p) => $p->has('ungradedClasses', 0));
    }
}
