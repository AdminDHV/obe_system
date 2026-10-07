<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Jobs\Calculations\RecalculateObeJob;
use App\Jobs\Imports\ImportBulkGradeJob;
use App\Jobs\Imports\ImportStudentRosterJob;
use App\Models\FileBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trước Sprint 41: lỗi cứng (Throwable/timeout) không được bắt → batch kẹt 'processing'
 * mãi, đo lường lỗi không để lại run nào. Test này chốt hành vi mới: mọi lỗi đều để dấu vết.
 */
class JobFailureVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function batch(string $status = 'queued'): FileBatch
    {
        return FileBatch::create([
            'user_id' => User::factory()->create()->id,
            'file_path' => 'imports/khong-ton-tai.xlsx',
            'type' => 'student_roster',
            'status' => $status,
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);
    }

    public function test_import_job_marks_batch_failed_when_file_missing(): void
    {
        $batch = $this->batch();

        (new ImportStudentRosterJob($batch, 1))->handle();

        $batch->refresh();
        $this->assertSame('failed', $batch->status, 'Batch phải chuyển failed, không kẹt processing');
        $this->assertNotEmpty($batch->error_log);
    }

    public function test_failed_hook_reconciles_stuck_processing_batch(): void
    {
        // Mô phỏng job chết cứng (timeout/OOM) sau khi đã set 'processing'.
        $batch = $this->batch('processing');

        (new ImportBulkGradeJob($batch, 1))->failed(new \Error('Hết bộ nhớ'));

        $this->assertSame('failed', $batch->refresh()->status);
    }

    public function test_failed_hook_does_not_overwrite_completed_batch(): void
    {
        $batch = $this->batch('completed');

        (new ImportBulkGradeJob($batch, 1))->failed(new \Error('trễ'));

        $this->assertSame('completed', $batch->refresh()->status, 'Không được ghi đè batch đã xong');
    }

    public function test_recalculate_failed_hook_records_failed_run(): void
    {
        (new RecalculateObeJob(schoolId: 1, triggeredBy: null, source: 'manual'))->failed(new \Error('nổ'));

        $this->assertDatabaseHas('measurement_runs', ['status' => 'failed', 'school_id' => 1]);
    }
}
