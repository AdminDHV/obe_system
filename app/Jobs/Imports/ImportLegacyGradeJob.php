<?php

namespace App\Jobs\Imports;

use App\Imports\LegacyGradeImport;
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
 * Nhập điểm khóa cũ (≤2024) tự chứa — tự xác lập khóa/lớp/SV từ file (chạy nền).
 */
class ImportLegacyGradeJob implements ShouldQueue
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

            AuditActor::runAs($this->batch->user_id, fn () => Excel::import(new LegacyGradeImport($this->batch, $this->schoolId), $path));

            $this->finalizeOutcome('khóa cũ cần Ngành + Môn + CLO khai sẵn (Bước 1) — kiểm tra các mã trong file khớp khung.');
            // Giữ file gốc (lưu trữ) — KHÔNG xóa.
        } catch (\Throwable $e) {
            $this->markFailed($e);
        }
    }
}
