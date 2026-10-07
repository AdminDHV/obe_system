<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ngữ cảnh "Trường đang làm việc" (Pha A): resolver ưu tiên ?school_id > cookie > trường đầu,
 * loại id giả/inactive; và share `schools` + `workingSchoolId` cho mọi trang Manager.
 */
class WorkingSchoolContextTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        foreach (['super_admin', 'lecturer', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('super_admin');
        return $u;
    }

    /** @return array{0: School, 1: School, 2: School} A(active), B(active), C(inactive) */
    private function schools(): array
    {
        // Migration tạo sẵn 1 trường DEFAULT — xoá để kiểm soát tập test.
        School::query()->forceDelete();

        return [
            School::create(['code' => 'AAA', 'name' => 'A School', 'is_active' => true]),
            School::create(['code' => 'BBB', 'name' => 'B School', 'is_active' => true]),
            School::create(['code' => 'CCC', 'name' => 'C School', 'is_active' => false]),
        ];
    }

    public function test_query_param_overrides_and_shares_active_schools(): void
    {
        $admin = $this->admin();
        [$a, $b] = $this->schools();

        $this->actingAs($admin)->get(route('manager.dashboard.index', ['school_id' => $b->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('workingSchoolId', $b->id)
                ->has('workingSchools', 2)); // chỉ 2 trường active (C ẩn)
    }

    public function test_falls_back_to_first_active_when_none_chosen(): void
    {
        $admin = $this->admin();
        [$a] = $this->schools();

        $this->actingAs($admin)->get(route('manager.dashboard.index'))
            ->assertInertia(fn (Assert $p) => $p->where('workingSchoolId', $a->id)); // 'A School' đầu bảng chữ cái
    }

    public function test_cookie_is_used_when_no_query(): void
    {
        $admin = $this->admin();
        [, $b] = $this->schools();

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $b->id)
            ->get(route('manager.dashboard.index'))
            ->assertInertia(fn (Assert $p) => $p->where('workingSchoolId', $b->id));
    }

    public function test_invalid_or_inactive_id_falls_back(): void
    {
        $admin = $this->admin();
        [$a, , $c] = $this->schools();

        // id inactive → fallback trường active đầu
        $this->actingAs($admin)->get(route('manager.dashboard.index', ['school_id' => $c->id]))
            ->assertInertia(fn (Assert $p) => $p->where('workingSchoolId', $a->id));
        // id không tồn tại → fallback
        $this->actingAs($admin)->get(route('manager.dashboard.index', ['school_id' => 999999]))
            ->assertInertia(fn (Assert $p) => $p->where('workingSchoolId', $a->id));
    }
}
