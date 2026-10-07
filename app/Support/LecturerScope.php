<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Giới hạn giảng viên trong PHẠM VI LỚP MÌNH DẠY.
 *
 * Trước đây `role:super_admin|lecturer` gác cả console: giảng viên đăng nhập là thấy — và sửa —
 * mọi lớp, mọi sinh viên của trường. `lecturer_id` chỉ là bộ lọc người dùng TỰ chọn, không phải
 * ràng buộc, nên không chặn được gì.
 *
 * Nguyên tắc: super_admin (người vận hành) không bị chạm; giảng viên bị kẹp theo
 * `course_classes.lecturer_id`. Kẹp ở TẦNG TRUY VẤN chứ không ở giao diện — ẩn nút không phải
 * là phân quyền.
 */
final class LecturerScope
{
    /** Người dùng hiện tại có bị kẹp không? super_admin thì không. */
    public static function applies(?Request $request = null): bool
    {
        $user = $request?->user() ?? Auth::user();

        return $user !== null
            && ! $user->hasRole('super_admin')
            && $user->hasRole('lecturer');
    }

    /** Kẹp một truy vấn lớp học phần về đúng lớp của giảng viên đang đăng nhập. */
    public static function classes(Builder $query, ?Request $request = null): Builder
    {
        if (! self::applies($request)) {
            return $query;
        }

        return $query->where('lecturer_id', ($request?->user() ?? Auth::user())->id);
    }

    /**
     * Id các lớp giảng viên được phép chạm tới. Trả `null` nghĩa là KHÔNG bị giới hạn
     * (super_admin) — khác hẳn mảng rỗng nghĩa là "không được lớp nào".
     *
     * @return array<int,int>|null
     */
    public static function allowedClassIds(?Request $request = null): ?array
    {
        if (! self::applies($request)) {
            return null;
        }

        return CourseClass::where('lecturer_id', ($request?->user() ?? Auth::user())->id)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Chặn giảng viên thao tác lên lớp không phải của mình.
     *
     * Trả 404 chứ không 403: 403 xác nhận "lớp này có thật", đủ để dò danh sách lớp của trường.
     */
    public static function assertOwns(CourseClass $class, ?Request $request = null): void
    {
        if (! self::applies($request)) {
            return;
        }

        abort_if(
            (int) $class->lecturer_id !== (int) ($request?->user() ?? Auth::user())->id,
            404,
        );
    }
}
