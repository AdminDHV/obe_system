<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Services\Manager\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RbacAuditTest extends TestCase
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

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_super_admin_can_create_user_and_assign_role(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('manager.users.store'), [
                'full_name' => 'Giảng viên Mới',
                'email' => 'new.lecturer@dhv.edu.vn',
                'staff_code' => 'GV-NEW-001',
                'password' => 'password123',
                'roles' => ['lecturer'],
                'is_active' => true,
            ])
            ->assertRedirect();

        $created = User::where('email', 'new.lecturer@dhv.edu.vn')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('lecturer'));
    }

    public function test_cannot_demote_the_last_super_admin(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->expectException(\DomainException::class);
        app(UserAccountService::class)->updateRoles($admin, ['lecturer']);
    }

    public function test_cannot_delete_own_account(): void
    {
        $admin = $this->superAdmin();
        // A second super admin so the "last super_admin" guard isn't what trips.
        $this->superAdmin();
        $this->actingAs($admin);

        $this->expectException(\DomainException::class);
        app(UserAccountService::class)->delete($admin);
    }

    public function test_toggle_active_blocks_self_lock(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();
        $this->actingAs($admin);

        $this->expectException(\DomainException::class);
        app(UserAccountService::class)->setActive($admin, false);
    }

    public function test_model_changes_are_audited_when_authenticated(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $before = AuditLog::count();
        $dept = Department::create(['code' => 'AUD', 'name' => 'Khoa Kiểm thử']);

        $this->assertGreaterThan($before, AuditLog::count());

        $log = AuditLog::where('model_type', 'Department')->where('model_id', $dept->id)->latest()->first();
        $this->assertNotNull($log);
        $this->assertSame('created', $log->action);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_no_audit_log_without_authenticated_user(): void
    {
        $before = AuditLog::count();
        Department::create(['code' => 'NOA', 'name' => 'Khoa Không Auth']);

        $this->assertSame($before, AuditLog::count());
    }
}
