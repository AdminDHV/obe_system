<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FileBatch;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chốt trạng thái cuối cùng của một lần import — NƠI DUY NHẤT quyết định "xong hay hỏng".
 *
 * Trước đây chỉ các Job import biết chốt trạng thái (qua trait `ReportsImportOutcome`), còn
 * `obe:validate-client-data` tự tạo FileBatch rồi gọi Excel::import đồng bộ và KHÔNG bao giờ
 * đánh dấu xong. Hậu quả: batch nằm mãi ở "Đang xử lý", và màn hình cảnh báo "đang xử lý lâu hơn
 * thường lệ — kiểm tra worker" khiến người vận hành đi tìm sự cố worker không hề tồn tại. Trên
 * production có 3 batch kẹt như vậy suốt hai tuần, dù dữ liệu đã vào đủ (42/42 · 2580/2580 ·
 * 4338/4338).
 *
 * Gom về một chỗ để mọi đường import — chạy nền hay chạy tay — đều kết thúc bằng một trạng thái
 * thật, không còn đường nào "quên" được.
 */
class ImportOutcome
{
    public const HINT_MAC_DINH = "kiểm tra Bước 1 (Khung) đã có ngành/môn/lớp/CLO tương ứng, hoặc Bước 3 'Kiểm tra sẵn sàng'.";

    /**
     * Có dòng nhưng KHÔNG dòng nào khớp khung ⇒ 'failed' kèm dòng tóm tắt đầu error_log, để người
     * vận hành thấy ngay thay vì phải cuộn đọc từng lỗi. Còn lại ⇒ 'completed'.
     */
    public static function finalize(FileBatch $batch, string $hint = self::HINT_MAC_DINH): void
    {
        $batch->refresh();

        $ok = (int) $batch->processed_rows;
        $total = (int) $batch->total_rows;
        $khongKhop = $total > 0 && $ok === 0;

        $batch->update([
            'status' => $khongKhop ? 'failed' : 'completed',
            'summary' => ['ok' => $ok, 'total' => $total, 'failed' => max(0, $total - $ok)],
            'error_log' => $khongKhop
                ? array_merge(["0/{$total} dòng khớp khung — {$hint}"], $batch->error_log ?? [])
                : ($batch->error_log ?? []),
        ]);
    }

    /** Lỗi cứng (Throwable/timeout/OOM): batch phải thành 'failed', không được kẹt 'processing'. */
    public static function fail(FileBatch $batch, Throwable $e, string $nguon): void
    {
        Log::channel('obe')->error('Import thất bại', [
            'nguon' => $nguon,
            'batch_id' => $batch->id ?? null,
            'type' => $batch->type ?? null,
            'error' => $e->getMessage(),
        ]);

        $batch->update([
            'status' => 'failed',
            'error_log' => array_merge($batch->error_log ?? [], ['Lỗi hệ thống: ' . $e->getMessage()]),
        ]);
    }
}
