<?php

namespace App\Jobs\Imports\Concerns;

use App\Support\ImportOutcome;

/**
 * Chốt kết quả 1 lần import: phản ánh TRUNG THỰC (không phải lúc nào cũng "Hoàn thành").
 * - Ghi summary {ok,total,failed}.
 * - Nếu có dòng mà KHÔNG dòng nào khớp khung (ok=0) → status='failed' + dòng tóm tắt
 *   đầu error_log để operator thấy ngay (không phải cuộn đọc từng lỗi).
 * - `markFailed()` + `failed()`: bắt LỖI CỨNG (Throwable/timeout/OOM) để batch không kẹt
 *   'processing' mãi, ghi cả `storage/logs` (channel obe) chứ không chỉ nuốt vào error_log.
 *
 * Yêu cầu: class dùng trait phải có thuộc tính `$this->batch` (FileBatch).
 */
trait ReportsImportOutcome
{
    /** Đánh dấu batch lỗi + ghi application log. Gọi trong catch (Throwable) của handle(). */
    protected function markFailed(\Throwable $e): void
    {
        ImportOutcome::fail($this->batch, $e, static::class);
    }

    /**
     * Hook queue: job chết cứng NGOÀI try/catch (timeout/OOM/Error) → đảm bảo batch
     * không kẹt 'queued'/'processing'. Chỉ chạm khi chưa được chốt trạng thái.
     */
    public function failed(\Throwable $e): void
    {
        $this->batch->refresh();
        if (in_array($this->batch->status, ['queued', 'processing'], true)) {
            $this->markFailed($e);
        }
    }

    protected function finalizeOutcome(string $hint = ImportOutcome::HINT_MAC_DINH): void
    {
        ImportOutcome::finalize($this->batch, $hint);
    }
}
