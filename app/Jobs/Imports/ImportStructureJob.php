<?php

namespace App\Jobs\Imports;

use App\Imports\StructureImport;
use App\Models\FileBatch;
use App\Services\OBE\StructureBuilderService;
use App\Support\AuditActor;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Dựng / cập nhật khung cấu trúc CTĐT từ file Excel nhiều sheet (chạy nền).
 */
class ImportStructureJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 1800;

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function handle(StructureBuilderService $builder): void
    {
        $this->batch->update(['status' => 'processing']);
        $path = $this->batch->file_path;

        try {
            if (!Storage::exists($path)) {
                throw new Exception('Không tìm thấy tệp tin tại: ' . $path);
            }

            // Chỉ đăng ký các sheet thực sự có trong file (file có thể thiếu sheet).
            $available = [];
            try {
                $fullPath = Storage::path($path);
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fullPath);
                $available = $reader->listWorksheetNames($fullPath);
            } catch (\Throwable $e) {
                // Không đọc được danh sách sheet → để StructureImport dùng mặc định.
            }

            $import = new StructureImport($available);
            $result = AuditActor::runAs($this->batch->user_id, function () use ($import, $path, $builder) {
                Excel::import($import, $path);
                return $builder->build($import->data, $this->schoolId);
            });

            $total = array_sum($result['stats']);
            $this->batch->update([
                'status' => 'completed',
                'processed_rows' => $total,
                'total_rows' => $total,
                // Cảnh báo "khung mới đổi cơ sở đo của khóa đã đo" đi CHUNG error_log để nổi lên
                // màn hình nhật ký import — đặt TRƯỚC lỗi vì nó nguy hiểm hơn lỗi từng dòng.
                'error_log' => array_merge(
                    $this->batch->error_log ?? [],
                    $result['warnings'] ?? [],
                    $result['errors'],
                ),
                'summary' => $result['stats'],
            ]);
            // Giữ file gốc (lưu trữ) để soi lại/chạy lại — KHÔNG xóa.
        } catch (\Throwable $e) {
            $this->markFailed($e);
        }
    }
}
