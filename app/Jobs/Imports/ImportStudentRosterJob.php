<?php

namespace App\Jobs\Imports;

use App\Imports\StudentRosterImport;
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
 * Nhập danh sách sinh viên hàng loạt cho 1 trường (chạy nền).
 */
class ImportStudentRosterJob implements ShouldQueue
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

            AuditActor::runAs($this->batch->user_id, fn () => Excel::import(new StudentRosterImport($this->batch, $this->schoolId), $path));

            $this->finalizeOutcome('cần khai báo Ngành (file Khung, Bước 1) trước; MSSV trùng sẽ được cập nhật.');
            // Giữ file gốc (lưu trữ) — KHÔNG xóa.
        } catch (\Throwable $e) {
            $this->markFailed($e);
        }
    }
}
