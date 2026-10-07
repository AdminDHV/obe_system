<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Ghi thời điểm đăng nhập gần nhất (users.last_login_at — cột đã có, trước đây KHÔNG bao
 * giờ được ghi) + log đăng nhập (kênh obe) cho bảo mật/truy vết.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        if (! $user) {
            return;
        }

        // saveQuietly: KHÔNG bắn model event → không tạo audit rác (User dùng Auditable).
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        Log::channel('obe')->info('Đăng nhập', [
            'user_id' => $user->getKey(),
            'email' => $user->email ?? null,
        ]);
    }
}
