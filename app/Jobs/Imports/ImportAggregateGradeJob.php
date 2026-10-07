<?php

namespace App\Jobs\Imports;

use App\Imports\AggregateGradeImport;
use App\Models\FileBatch;
use App\Support\AuditActor;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Nhập điểm TỔNG học phần (chế độ điểm tổng → PI/PLO) — tự dựng khóa/lớp/SV từ file (chạy nền).
 */
class ImportAggregateGradeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    use \App\Jobs\Imports\Concerns\ReportsImportOutcome;

    public $tries = 1;
    public $timeout = 1800;

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function handle(): void
    {
        $this->batch->update(['status' => 'processing']);
        $path = $this->batch->file_path;

        try {
            if (!Storage::exists($path)) {
                throw new Exception('Không tìm thấy tệp tin tại: ' . $path);
            }

            AuditActor::runAs($this->batch->user_id, fn () => Excel::import(new AggregateGradeImport($this->batch, $this->schoolId), $path));

            $this->finalizeOutcome("chế độ 'điểm tổng → PI/PLO' cần Ngành + Môn + CLO + PLO/PI + ma trận CLO→PI (Bước 1).");
            // Giữ file gốc (lưu trữ) — KHÔNG xóa.
        } catch (\Throwable $e) {
            $this->markFailed($e);
        }
    }
}
