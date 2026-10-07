<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Models\FileBatch;
use App\Services\Import\CourseFrameReader;
use App\Services\OBE\CourseFrameService;
use App\Support\AuditActor;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Nhập FILE KHUNG HỌC PHẦN THEO ĐỢT (chạy nền). Như phiếu lớp: có lỗi thì không ghi gì, chỉ có hai
 * trạng thái rõ ràng — "Hoàn thành" kèm số liệu, hoặc "Thất bại" kèm danh sách lỗi theo ô.
 */
class ImportCourseFrameJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    use \App\Jobs\Imports\Concerns\ReportsImportOutcome;

    public $tries = 1;
    public $timeout = 1800;

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function handle(CourseFrameReader $reader, CourseFrameService $service): void
    {
        $this->batch->update(['status' => 'processing']);

        try {
            if (! Storage::exists($this->batch->file_path)) {
                throw new Exception('Không tìm thấy tệp tin tại: ' . $this->batch->file_path);
            }

            $frame = $reader->read(Storage::path($this->batch->file_path));
            $result = AuditActor::runAs(
                $this->batch->user_id,
                fn () => $service->import($frame, $this->schoolId),
            );

            if ($result['errors'] !== []) {
                $this->batch->update([
                    'status' => 'failed',
                    'processed_rows' => 0,
                    'total_rows' => 0,
                    'error_log' => array_merge(
                        ['File khung chưa được nhập — sửa ' . count($result['errors']) . ' lỗi dưới đây rồi tải lên lại (chưa ghi dữ liệu nào).'],
                        $result['errors'],
                    ),
                ]);

                return;
            }

            $n = ($result['stats']['mon'] ?? 0) + ($result['stats']['lop'] ?? 0);
            $this->batch->update([
                'status' => 'completed',
                'processed_rows' => $n,
                'total_rows' => $n,
                'summary' => $result['stats'],
                'error_log' => $result['warnings'],
            ]);
            // Giữ file gốc để soi lại — KHÔNG xóa.
        } catch (\Throwable $e) {
            $this->markFailed($e);
        }
    }
}
