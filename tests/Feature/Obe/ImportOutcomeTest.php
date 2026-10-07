<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\BulkGradeImport;
use App\Jobs\Imports\Concerns\ReportsImportOutcome;
use App\Models\FileBatch;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import điểm mà KHÔNG dòng nào khớp khung → kết quả phải TRUNG THỰC:
 * processed_rows=0, có lỗi, và batch bị đánh dấu 'failed' (không "Hoàn thành").
 */
class ImportOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_match_import_is_marked_failed(): void
    {
        $school = School::create(['code' => 'SCH-O', 'name' => 'Outcome']);

        $batch = FileBatch::create([
            'user_id' => User::factory()->create()->id,
            'school_id' => $school->id,
            'type' => 'grade_bulk',
            'file_path' => 'imports/test.csv',
            'file_name' => 'test.csv',
            'status' => 'processing',
            'processed_rows' => 0,
            'total_rows' => 0,
        ]);

        // Dòng trỏ Mã lớp KHÔNG tồn tại trong khung → không khớp.
        (new BulkGradeImport($batch, $school->id))->collection(collect([
            ['ma_lop' => 'KHONG_CO', 'mssv' => 'SV1', 'ten_bai_danh_gia' => 'GK', 'diem' => 7],
            ['ma_lop' => 'KHONG_CO', 'mssv' => 'SV2', 'ten_bai_danh_gia' => 'GK', 'diem' => 8],
        ]));

        $batch->refresh();
        $this->assertSame(0, (int) $batch->processed_rows);
        $this->assertSame(2, (int) $batch->total_rows);
        $this->assertNotEmpty($batch->error_log);

        // Chốt kết quả (như job làm sau Excel::import).
        $runner = new class($batch) {
            use ReportsImportOutcome;
            public function __construct(public FileBatch $batch) {}
            public function run(): void { $this->finalizeOutcome(); }
        };
        $runner->run();

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, $batch->summary['ok']);
        $this->assertSame(2, $batch->summary['total']);
    }
}
