<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phương án đo lường chuẩn đầu ra theo khóa — NGUỒN DUY NHẤT cho mốc năm.
 * Mọi nơi cần biết "khóa này thuộc phương án nào" phải đi qua đây, KHÔNG hard-code
 * lại con số 2024/2025/2026.
 *
 *   - Legacy (≤2024): chỉ đo CLO (gán điểm tổng cho mọi CLO), bỏ PI/PLO.
 *   - Transition2025 (==2025): CLO nối thẳng PLO (PLO không chia PI).
 *   - Standard (≥2026): CLO → PI → PLO.
 *
 * Lưu ý: Transition2025 và Standard dùng CHUNG cách tính (StandardMeasurementStrategy);
 * khác biệt chỉ ở dữ liệu khung (PLO có chia PI hay CLO nối thẳng PLO), không ở thuật toán.
 */
enum CohortStrategy: string
{
    case Legacy = 'legacy';
    case Transition2025 = 'transition_2025';
    case Standard = 'standard';

    public static function fromStartYear(int $year): self
    {
        return match (true) {
            $year <= 2024 => self::Legacy,
            $year === 2025 => self::Transition2025,
            default => self::Standard,
        };
    }

    /** Khóa cũ ≤2024 — chỉ đo CLO. */
    public function isLegacy(): bool
    {
        return $this === self::Legacy;
    }

    /** Mã phương án 1/2/3 (dùng cho readiness/UI). */
    public function code(): int
    {
        return match ($this) {
            self::Legacy => 1,
            self::Transition2025 => 2,
            self::Standard => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Legacy => 'Phương án 1 — Khóa cũ (≤2024, chỉ đo CLO)',
            self::Transition2025 => 'Phương án 2 — Khóa 2025',
            self::Standard => 'Phương án 3 — Khóa mới (≥2026, đủ CLO→PI→PLO)',
        };
    }
}
