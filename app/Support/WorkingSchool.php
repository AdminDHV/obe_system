<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Trường đang làm việc" (tenant context) — NƠI DUY NHẤT quyết định request thuộc trường nào.
 *
 * CÁCH LY CỨNG PER-USER:
 * - super_admin = operator toàn hệ thống → chuyển trường tự do
 *   (ưu tiên ?school_id deep-link → cookie obe_working_school → trường active đầu tiên).
 * - Mọi role khác → KẸP CỨNG vào `users.school_id`; bỏ qua ?school_id/cookie (không forge được).
 *   Chưa gán trường (null) → KHÔNG thuộc trường nào (query scope-theo-id trả rỗng).
 *
 * Đây là ranh giới bảo mật server-side: controller chỉ tin `WorkingSchool::id()`, KHÔNG tin payload.
 */
class WorkingSchool
{
    public const COOKIE = 'obe_working_school';
    public const ATTR = 'workingSchoolId';

    /** Danh sách trường active (id, code, name) — dùng cho switcher + validate. */
    public static function activeSchools()
    {
        return School::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    /**
     * Trường mà user ĐƯỢC PHÉP làm việc / chuyển đổi (nguồn cho switcher).
     * super_admin → mọi trường active; role khác → đúng trường của mình (rỗng nếu chưa gán).
     */
    public static function switchableSchools(?User $user)
    {
        $active = self::activeSchools();
        if ($user && ! $user->isGlobalOperator()) {
            return $user->school_id
                ? $active->where('id', $user->school_id)->values()
                : $active->take(0)->values();
        }

        return $active;
    }

    /** Quyết định trường của request (null nếu không có trường / user chưa gán trường). */
    public static function resolve(Request $request): ?int
    {
        $user = $request->user();

        // Non-super_admin: kẹp cứng vào trường của user, bỏ qua mọi ?school_id/cookie.
        if ($user && ! $user->isGlobalOperator()) {
            return $user->school_id ? (int) $user->school_id : null;
        }

        // super_admin (operator toàn cục): ?school_id → cookie → trường active đầu.
        $active = self::activeSchools();
        if ($active->isEmpty()) {
            return null;
        }
        $validIds = $active->pluck('id')->map(fn ($i) => (string) $i)->all();

        foreach ([$request->query('school_id'), $request->cookie(self::COOKIE)] as $candidate) {
            if ($candidate !== null && $candidate !== '' && in_array((string) $candidate, $validIds, true)) {
                return (int) $candidate;
            }
        }

        return (int) $active->first()->id;
    }

    /** Đọc trường đã resolve cho request hiện tại (do ResolveWorkingSchool middleware set). */
    public static function id(Request $request): ?int
    {
        return $request->attributes->get(self::ATTR);
    }
}
