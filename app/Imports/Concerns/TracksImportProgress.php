<?php

declare(strict_types=1);

namespace App\Imports\Concerns;

/**
 * Đếm số dòng đã xử lý của một FileBatch mà KHÔNG ghi DB từng dòng.
 *
 * Vì sao cần: `$batch->increment('processed_rows')` mỗi dòng nghĩa là UPDATE lặp lại trên CÙNG
 * MỘT dòng, bên trong transaction dài của Excel::import. PostgreSQL lưu mỗi lần UPDATE thành một
 * phiên bản mới; transaction chưa đóng nên autovacuum không dọn được, chuỗi phiên bản dài dần và
 * mỗi UPDATE sau lại phải đi hết chuỗi đó ⇒ chi phí BẬC HAI theo số dòng.
 *
 * Đo thực tế trên file 4338 dòng câu hỏi (khóa 2023): riêng câu UPDATE này ngốn 242 giây trên
 * tổng 380 giây import — 8.4 ms/lần ở đầu file, 161.7 ms/lần ở cuối. Gộp lại theo lô đưa số lần
 * UPDATE từ 4338 xuống ~22.
 *
 * Lớp dùng trait phải có thuộc tính `$batch` (App\Models\FileBatch).
 */
trait TracksImportProgress
{
    private int $pendingProgress = 0;

    /** Ghi nhận 1 dòng đã xử lý; chỉ chạm DB sau mỗi $flushEvery dòng. */
    protected function markRowProcessed(int $flushEvery = 200): void
    {
        $this->pendingProgress++;

        if ($this->pendingProgress >= $flushEvery) {
            $this->flushProgress();
        }
    }

    /** Đẩy nốt phần còn lại xuống DB — PHẢI gọi sau vòng lặp, kể cả khi có lỗi. */
    protected function flushProgress(): void
    {
        if ($this->pendingProgress > 0) {
            $this->batch->increment('processed_rows', $this->pendingProgress);
            $this->pendingProgress = 0;
        }
    }
}
