<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\FileBatch;
use App\Models\MeasurementRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Màn hình Nhật ký đầy đủ (Sprint 43): lịch sử import + nhật ký đo lường, phân trang + lọc.
 */
class SystemLogScreensTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');
        return $admin;
    }

    public function test_import_batches_index_lists_and_filters_by_type(): void
    {
        $admin = $this->admin();
        FileBatch::create(['user_id' => $admin->id, 'file_path' => 'a', 'type' => 'grade_bulk', 'status' => 'completed', 'processed_rows' => 3, 'total_rows' => 3]);
        FileBatch::create(['user_id' => $admin->id, 'file_path' => 'b', 'type' => 'structure', 'status' => 'completed', 'processed_rows' => 5, 'total_rows' => 5]);

        $this->actingAs($admin)->get(route('manager.import-batches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Manager/ImportBatches/Index')->has('batches.data', 2));

        $this->actingAs($admin)->get(route('manager.import-batches.index', ['type' => 'structure']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('batches.data', 1)
                ->where('batches.data.0.type', 'structure'));
    }

    public function test_measurement_runs_index_lists_and_filters_by_status(): void
    {
        $admin = $this->admin();
        MeasurementRun::create(['school_id' => 1, 'source' => 'manual', 'pairs_count' => 10, 'status' => 'completed']);
        MeasurementRun::create(['school_id' => 1, 'source' => 'manual', 'pairs_count' => 0, 'status' => 'failed']);

        $this->actingAs($admin)->get(route('manager.measurement-runs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Manager/MeasurementRuns/Index')->has('runs.data', 2));

        $this->actingAs($admin)->get(route('manager.measurement-runs.index', ['status' => 'failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('runs.data', 1)
                ->where('runs.data.0.status', 'failed'));
    }

    public function test_lecturer_cannot_reach_super_admin_log_screens(): void
    {
        $lecturer = User::factory()->create(['is_active' => true]);
        $lecturer->assignRole('lecturer');

        $this->actingAs($lecturer)->get(route('manager.import-batches.index'))->assertForbidden();
    }
}
