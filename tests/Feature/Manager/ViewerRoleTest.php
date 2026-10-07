<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Cohort;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vai trò `viewer` — "Người xem (Cố vấn học tập)": xem toàn trường của mình, KHÔNG ghi được gì,
 * và KHÔNG chạm được vào mã tra cứu.
 */
class ViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'lecturer', 'viewer'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $dept = Department::create(['school_id' => $this->school->id, 'code' => 'D1', 'name' => 'Khoa 1']);
        $program = Program::create([
            'school_id' => $this->school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành 1',
        ]);
        $cohort = Cohort::create([
            'school_id' => $this->school->id, 'program_id' => $program->id, 'name' => '2026', 'start_year' => 2026,
        ]);

        $this->student = Student::create([
            'school_id' => $this->school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id,
            'student_code' => 'SV01', 'full_name' => 'Nguyễn Văn A', 'status' => 'studying',
            'lookup_code' => 'ABCD2345',
        ]);
    }

    private function viewer(): User
    {
        $u = User::factory()->create(['school_id' => $this->school->id]);
        $u->assignRole('viewer');

        return $u;
    }

    public function test_viewer_can_read_the_pages_an_advisor_needs(): void
    {
        $this->actingAs($this->viewer());

        $hong = [];
        foreach (['manager.students.index', 'manager.classes.index', 'manager.programs.index',
            'manager.dashboard.index', 'manager.obe.index', 'manager.templates.index'] as $name) {
            $status = $this->get(route($name))->getStatusCode();
            if ($status !== 200) {
                $hong[] = $name . ' → ' . $status;
            }
        }

        // Báo rõ route nào hỏng, không chỉ "expected 200 got 403".
        $this->assertSame([], $hong, 'Người xem phải vào được: ' . implode(', ', $hong));

        $this->get(route('manager.students.show', $this->student->uuid))->assertOk();
    }

    /**
     * Chặn theo METHOD nên route ghi nào cũng bị, kể cả route viết sau này. Đây là lý do dùng
     * middleware thay vì liệt kê tay hơn 80 route ghi.
     */
    public function test_every_write_is_refused_even_on_pages_the_viewer_can_read(): void
    {
        $this->actingAs($this->viewer());

        $this->put(route('manager.students.update', $this->student->uuid), ['full_name' => 'Đổi tên'])
            ->assertForbidden();
        $this->delete(route('manager.students.destroy', $this->student->uuid))->assertForbidden();
        $this->put(route('manager.students.lookup-code', $this->student->uuid))->assertForbidden();
        $this->post(route('manager.courses.store'), [])->assertForbidden();

        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'full_name' => 'Nguyễn Văn A']);
    }

    /** Mã tra cứu là bí mật riêng của học viên — mở trang không được thành phát mã cả trường. */
    public function test_viewer_never_sees_the_lookup_code(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('manager.students.show', $this->student->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('student.lookup_code', null)
                ->where('canManage', false));

        // Phiếu cá nhân IN mã lên giấy → người xem không được xuất.
        $this->get(route('manager.students.export-plo', $this->student->uuid))->assertForbidden();
        $this->get(route('manager.students.export-plo-bulk', ['program_id' => 1]))->assertForbidden();
    }

    /** Quản trị hệ thống thì người xem không được bén mảng, kể cả chỉ đọc. */
    public function test_viewer_cannot_reach_system_administration(): void
    {
        $this->actingAs($this->viewer());

        foreach (['manager.users.index', 'manager.audit-logs.index', 'manager.schools.index',
            'manager.import-batches.index', 'manager.measurement-runs.index'] as $name) {
            $this->get(route($name))->assertForbidden();
        }
    }

    /** Người vận hành KHÔNG bị middleware chặn nhầm. */
    public function test_super_admin_is_untouched(): void
    {
        $admin = User::factory()->create(['school_id' => $this->school->id]);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->get(route('manager.students.show', $this->student->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', true));

        $this->actingAs($admin)->get(route('manager.users.index'))->assertOk();
    }
}
