<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Rào an toàn khi giao khách: ghi last_login (không audit rác) + nút dữ liệu mẫu chỉ super_admin.
 */
class HandoffHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        foreach (['super_admin', 'lecturer', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_login_records_last_login_without_audit_noise(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        event(new Login('web', $user, false));

        $this->assertNotNull($user->fresh()->last_login_at, 'last_login_at phải được ghi khi đăng nhập');
        // saveQuietly → không tạo audit 'updated' cho User mỗi lần đăng nhập.
        $this->assertSame(0, AuditLog::where('model_type', 'User')->count());
    }

    public function test_demo_routes_require_super_admin(): void
    {
        $this->seedRoles();

        $lecturer = User::factory()->create(['is_active' => true]);
        $lecturer->assignRole('lecturer');
        $this->actingAs($lecturer)->post(route('manager.reports.demo.load'))->assertForbidden();

        // super_admin qua được (clear là no-op khi chưa có demo → redirect).
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin)->post(route('manager.reports.demo.clear'))->assertStatus(302);
    }
}
