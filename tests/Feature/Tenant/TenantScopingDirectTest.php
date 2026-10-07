<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pha B — scope theo trường cho các thực thể DIRECT: index chỉ ra trường đang chọn,
 * tạo mới đóng dấu trường, mã unique THEO từng trường, cha khác trường bị chặn.
 */
class TenantScopingDirectTest extends TestCase
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
        School::query()->forceDelete(); // bỏ trường DEFAULT của migration
        $this->a = School::create(['code' => 'AAA', 'name' => 'A School', 'is_active' => true]);
        $this->b = School::create(['code' => 'BBB', 'name' => 'B School', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('super_admin');
    }

    /** Đặt cookie trường đang làm việc rồi thực hiện request. */
    private function as(School $school)
    {
        return $this->actingAs($this->admin)->withUnencryptedCookie('obe_working_school', (string) $school->id);
    }

    public function test_index_shows_only_working_school_rows(): void
    {
        Department::create(['code' => 'CNTT', 'name' => 'Khoa A', 'school_id' => $this->a->id]);
        Department::create(['code' => 'CNTT', 'name' => 'Khoa B', 'school_id' => $this->b->id]);

        $this->as($this->a)->get(route('manager.departments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('departments.data', 1)
                ->where('departments.data.0.name', 'Khoa A'));

        $this->as($this->b)->get(route('manager.departments.index'))
            ->assertInertia(fn (Assert $p) => $p->has('departments.data', 1)
                ->where('departments.data.0.name', 'Khoa B'));
    }

    public function test_store_stamps_working_school_and_same_code_allowed_across_schools(): void
    {
        $this->as($this->a)->post(route('manager.departments.store'), ['code' => 'X', 'name' => 'Khoa X'])
            ->assertSessionHasNoErrors();
        $this->as($this->b)->post(route('manager.departments.store'), ['code' => 'X', 'name' => 'Khoa X'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departments', ['code' => 'X', 'school_id' => $this->a->id]);
        $this->assertDatabaseHas('departments', ['code' => 'X', 'school_id' => $this->b->id]);
    }

    public function test_duplicate_code_within_same_school_is_rejected(): void
    {
        Department::create(['code' => 'DUP', 'name' => 'K', 'school_id' => $this->a->id]);

        $this->as($this->a)->post(route('manager.departments.store'), ['code' => 'DUP', 'name' => 'K2'])
            ->assertSessionHasErrors('code');
    }

    public function test_course_code_unique_now_enforced_per_school(): void
    {
        $this->as($this->a)->post(route('manager.courses.store'), ['code' => 'C1', 'name' => 'Môn', 'credits' => 3])
            ->assertSessionHasNoErrors();
        $this->as($this->a)->post(route('manager.courses.store'), ['code' => 'C1', 'name' => 'Môn 2', 'credits' => 3])
            ->assertSessionHasErrors('code');
        // Cùng mã ở trường khác → OK.
        $this->as($this->b)->post(route('manager.courses.store'), ['code' => 'C1', 'name' => 'Môn', 'credits' => 3])
            ->assertSessionHasNoErrors();
    }

    public function test_cross_school_parent_is_rejected(): void
    {
        // Khoa thuộc trường A; tạo Ngành ở trường B gắn khoa A → phải bị chặn.
        $deptA = Department::create(['code' => 'K', 'name' => 'Khoa A', 'school_id' => $this->a->id]);

        $this->as($this->b)->post(route('manager.programs.store'), [
            'code' => 'P', 'name' => 'Ngành', 'department_id' => $deptA->id,
        ])->assertSessionHasErrors('department_id');
    }
}
