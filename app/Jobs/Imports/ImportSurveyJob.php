<?php

namespace App\Jobs\Imports;

use App\Imports\SurveyResultImport;
use App\Models\FileBatch;
use App\Support\AuditActor;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportSurveyJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    use \App\Jobs\Imports\Concerns\ReportsImportOutcome;

    public $tries = 1;
    public $timeout = 600;

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

            AuditActor::runAs($this->batch->user_id, fn () => Excel::import(new SurveyResultImport($this->batch, $this->schoolId), $filePath));

            $this->finalizeOutcome('kiểm tra Mã ngành / Mã PLO trong file khớp khung (Bước 1).');
            // Giữ file gốc (lưu trữ) — KHÔNG xóa.
        } catch (\Throwable $e) {
            $this->markFailed($e);
        }
    }
}
