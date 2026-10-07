<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * "Người thực hiện" cho audit khi KHÔNG có phiên đăng nhập (queue job import, command).
 *
 * `Auditable::writeAuditLog` vốn bỏ qua khi `Auth::check()` = false (job chạy nền không
 * có user) → thay đổi điểm qua import không để lại vết. Bọc phần ghi DB trong
 * `AuditActor::runAs($userId, fn () => ...)` để quy các thay đổi cho người đã upload file.
 *
 * Không set actor → vẫn skip như cũ (giữ seeder/migrate:fresh an toàn, không tạo audit rác).
 */
class AuditActor
{
    private static ?int $actorId = null;

    /** ID người thực hiện đang được ép (nếu có). */
    public static function id(): ?int
    {
        return self::$actorId;
    }

    /** Chạy $callback với actor được ép; luôn khôi phục giá trị cũ sau khi xong. */
    public static function runAs(?int $userId, Closure $callback): mixed
    {
        $previous = self::$actorId;
        self::$actorId = $userId;
        try {
            return $callback();
        } finally {
            self::$actorId = $previous;
        }
    }
}
