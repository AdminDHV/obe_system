<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Cohort;
use App\Models\Department;
use App\Models\Program;
use App\Models\ReportSnapshot;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Chặn tạo báo cáo AUN-QA khi khung CHƯA đủ điều kiện đo (readiness != ready).
 */
class ReportReadinessGateTest extends TestCase
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

    public function test_generate_is_blocked_when_not_ready(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');

        $school = School::create(['code' => 'SCH-G', 'name' => 'Gate']);
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM', 'school_id' => $school->id]);
        // Khóa KHÔNG có lớp/CLO/PLO → readiness chắc chắn "chưa sẵn sàng".
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026, 'school_id' => $school->id]);

        // Chọn trường đang làm việc = trường của ngành/khóa (ngữ cảnh vận hành thật).
        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $school->id)
            ->post(route('manager.reports.generate'), [
                'program_id' => $program->id,
                'cohort_id' => $cohort->id,
            ])
            ->assertSessionHas('error');

        // KHÔNG tạo báo cáo nào.
        $this->assertSame(0, ReportSnapshot::count());
    }
}
