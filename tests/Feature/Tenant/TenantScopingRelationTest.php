<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pha C — scope theo trường cho thực thể RELATION (Đề cương/Thành phần/Rubric/Giảng viên)
 * qua quan hệ; và chặn tạo con gắn cha KHÁC trường.
 */
class TenantScopingRelationTest extends TestCase
{
    use RefreshDatabase;

    private School $a;
    private School $b;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'lecturer', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        School::query()->forceDelete();
        $this->a = School::create(['code' => 'AAA', 'name' => 'A School', 'is_active' => true]);
        $this->b = School::create(['code' => 'BBB', 'name' => 'B School', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('super_admin');
    }

    private function as(School $school)
    {
        return $this->actingAs($this->admin)->withUnencryptedCookie('obe_working_school', (string) $school->id);
    }

    /** @return array{dept: Department, course: Course, syllabus: CourseSyllabus, assessment: Assessment} */
    private function chain(School $s): array
    {
        $dept = Department::create(['code' => 'K' . $s->id, 'name' => 'Khoa', 'school_id' => $s->id]);
        $course = Course::create(['code' => 'C' . $s->id, 'name' => 'Môn', 'credits' => 3, 'school_id' => $s->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $assessment = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Cuối kỳ', 'assessment_category' => 'final', 'weight_percent' => 60, 'is_rubric_based' => true]);

        return compact('dept', 'course', 'syllabus', 'assessment');
    }

    public function test_syllabus_and_assessment_index_scoped_via_relation(): void
    {
        $this->chain($this->a);
        $this->chain($this->b);

        $this->as($this->a)->get(route('manager.syllabi.index'))
            ->assertInertia(fn (Assert $p) => $p->has('syllabi.data', 1));
        $this->as($this->a)->get(route('manager.assessments.index'))
            ->assertInertia(fn (Assert $p) => $p->has('assessments.data', 1));
    }

    public function test_lecturer_index_scoped_via_department(): void
    {
        $ca = $this->chain($this->a);
        $cb = $this->chain($this->b);
        $this->makeLecturer($ca['dept']->id, 'GVA');
        $this->makeLecturer($cb['dept']->id, 'GVB');

        $this->as($this->a)->get(route('manager.lecturers.index'))
            ->assertInertia(fn (Assert $p) => $p->has('lecturers.data', 1)
                ->where('lecturers.data.0.staff_code', 'GVA'));
    }

    public function test_cross_school_parent_rejected_on_create(): void
    {
        $ca = $this->chain($this->a); // cha ở trường A

        // Đề cương ở trường B gắn môn của A
        $this->as($this->b)->post(route('manager.syllabi.store'), ['course_id' => $ca['course']->id, 'version' => '2.0'])
            ->assertSessionHasErrors('course_id');

        // Thành phần đánh giá ở B gắn đề cương của A
        $this->as($this->b)->post(route('manager.assessments.store'), [
            'syllabus_id' => $ca['syllabus']->id, 'name' => 'X', 'assessment_category' => 'process', 'weight_percent' => 40,
        ])->assertSessionHasErrors('syllabus_id');

        // Rubric ở B gắn đầu điểm của A
        $this->as($this->b)->post(route('manager.rubrics.store'), ['assessment_id' => $ca['assessment']->id, 'name' => 'R'])
            ->assertSessionHasErrors('assessment_id');

        // Giảng viên ở B gắn khoa của A
        $this->as($this->b)->post(route('manager.lecturers.store'), [
            'department_id' => $ca['dept']->id, 'staff_code' => 'GVX', 'full_name' => 'GV', 'email' => 'gvx@x.local',
            'password' => 'password123', 'position' => 'GV', 'phone' => '0900000000',
        ])->assertSessionHasErrors('department_id');
    }

    private function makeLecturer(int $deptId, string $code): User
    {
        $u = User::factory()->create(['department_id' => $deptId, 'staff_code' => $code, 'is_active' => true]);
        $u->assignRole('lecturer');
        return $u;
    }
}
