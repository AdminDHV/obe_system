<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Models\FileBatch;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Support\AuditActor;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Nhập PHIẾU LỚP HỌC PHẦN (chạy nền) — một file một lớp.
 *
 * Khác các file điểm cũ: phiếu có lỗi thì KHÔNG ghi gì cả (xem ClassSheetService), nên kết quả chỉ
 * có hai trạng thái rõ ràng — "Hoàn thành" với đủ số liệu, hoặc "Thất bại" kèm danh sách lỗi theo ô.
 */
class ImportClassSheetJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    use \App\Jobs\Imports\Concerns\ReportsImportOutcome;

    public $tries = 1;
    public $timeout = 1800;

    /**
     * @param array{bo_sung?: bool, lop_id?: int|null} $opt xem ClassSheetService::import
     */
    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
        private array $opt = [],
    ) {}

    public function handle(ClassSheetReader $reader, ClassSheetService $service): void
    {
        $this->batch->update(['status' => 'processing']);

        try {
            if (! Storage::exists($this->batch->file_path)) {
                throw new Exception('Không tìm thấy tệp tin tại: ' . $this->batch->file_path);
            }

            $sheet = $reader->read(Storage::path($this->batch->file_path));
            $result = AuditActor::runAs(
                $this->batch->user_id,
                fn () => $service->import($sheet, $this->schoolId, $this->opt),
            );

            if ($result['errors'] !== []) {
                $this->batch->update([
                    'status' => 'failed',
                    'processed_rows' => 0,
                    'total_rows' => 0,
                    'error_log' => array_merge(
                        ['Phiếu chưa được nhập — sửa ' . count($result['errors']) . ' lỗi dưới đây rồi tải lên lại (chưa ghi dữ liệu nào).'],
                        $result['errors'],
                    ),
                ]);

                return;
            }

            $students = $result['stats']['sinh_vien_trong_phieu'] ?? 0;
            $this->batch->update([
                'status' => 'completed',
                'processed_rows' => $students,
                'total_rows' => $students,
                'summary' => $result['stats'],
                // Cảnh báo (vd phiếu làm đổi ma trận dùng chung của môn) — nhập vẫn thành công.
                'error_log' => $result['warnings'] ?? [],
            ]);
            // Giữ file gốc để soi lại — KHÔNG xóa.
        } catch (\Throwable $e) {
            $this->markFailed($e);
        }
    }
}
