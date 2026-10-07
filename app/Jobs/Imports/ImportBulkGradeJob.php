<?php

namespace App\Jobs\Imports;

use App\Imports\BulkGradeImport;
use App\Models\FileBatch;
use App\Support\AuditActor;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportBulkGradeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    use \App\Jobs\Imports\Concerns\ReportsImportOutcome;

    public $tries = 1;
    public $timeout = 1800; // 30 phút cho file cả khóa

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function handle(): void
    {
        $this->batch->update(['status' => 'processing']);

        $filePath = $this->batch->file_path;

        try {
            if (!Storage::exists($filePath)) {
                throw new Exception("Không tìm thấy tệp tin tại: " . $filePath);
            }

            AuditActor::runAs($this->batch->user_id, fn () => Excel::import(new BulkGradeImport($this->batch, $this->schoolId), $filePath));

            $this->finalizeOutcome();
            // Giữ file gốc (lưu trữ) — KHÔNG xóa.
        } catch (\Throwable $e) {
            $this->markFailed($e);
        }
    }
}
