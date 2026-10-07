<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\AuditLog;
use App\Models\MeasurementRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retention: `model:prune` dọn nhật ký quá hạn (audit ~2 năm, run ~180 ngày),
 * giữ bản ghi trong hạn. (file_batches KHÔNG prune — giữ lịch sử import.)
 */
class RetentionPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_old_audit_logs_keeps_recent(): void
    {
        $user = User::factory()->create();
        $old = AuditLog::create(['user_id' => $user->id, 'action' => 'updated', 'model_type' => 'StudentGrade', 'model_id' => 1]);
        $old->forceFill(['created_at' => now()->subDays(800)])->save();
        $recent = AuditLog::create(['user_id' => $user->id, 'action' => 'updated', 'model_type' => 'StudentGrade', 'model_id' => 2]);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    }

    public function test_prunes_old_measurement_runs_keeps_recent(): void
    {
        $old = MeasurementRun::create(['school_id' => 1, 'source' => 'manual', 'pairs_count' => 1, 'status' => 'completed']);
        $old->forceFill(['created_at' => now()->subDays(200)])->save();
        $recent = MeasurementRun::create(['school_id' => 1, 'source' => 'manual', 'pairs_count' => 1, 'status' => 'completed']);

        $this->artisan('model:prune', ['--model' => [MeasurementRun::class]])->assertOk();

        $this->assertDatabaseMissing('measurement_runs', ['id' => $old->id]);
        $this->assertDatabaseHas('measurement_runs', ['id' => $recent->id]);
    }
}
