<?php

declare(strict_types=1);

/**
 * Cấu hình vận hành OBE. Đọc qua config() (KHÔNG env() trực tiếp trong code) để an toàn
 * khi `php artisan config:cache` (deploy.sh có chạy) — env() trả null sau khi cache.
 */
return [
    // Giữ nhật ký thay đổi (audit) — mặc định ~2 năm.
    'audit_log_keep_days' => (int) env('AUDIT_LOG_KEEP_DAYS', 730),

    // Giữ nhật ký lần đo (measurement runs) — mặc định 180 ngày.
    'measurement_run_keep_days' => (int) env('MEASUREMENT_RUN_KEEP_DAYS', 180),
];
