<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\BelongsToSchool;
use App\Support\WorkingSchool;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bản ghi lấy từ URL phải thuộc TRƯỜNG ĐANG LÀM VIỆC — chặn một lần cho mọi route.
 *
 * Rà lại toàn bộ console thấy 21 route sửa/xóa nhận `{uuid}` mà KHÔNG kiểm trường:
 * `courses.destroy` nhận `Course $course` rồi `->delete()` thẳng, `classes.update` thì validate
 * theo trường của CHÍNH LỚP BỊ SỬA thay vì trường đang làm việc. Model cũng không có global scope,
 * nên route binding lấy được bất kỳ bản ghi nào của bất kỳ trường nào.
 *
 * Đây không phải leo thang quyền — chỉ người vận hành mới vào được, mà họ vốn chuyển trường tự do.
 * Rủi ro thật là THAO TÁC NHẦM TRƯỜNG: đang làm ở trường A, mở lại một URL cũ, xóa trúng dữ liệu
 * trường B. Với sản phẩm một người vận hành nhiều trường thì việc đó sẽ xảy ra, và xóa thì không
 * hoàn tác được.
 *
 * Chặn ở đây thay vì sửa 21 controller: sửa từng nơi thì chỉ cần một người thêm route mới mà quên
 * là thủng lại. Cùng lý lẽ với `EnsureReadOnlyRole`.
 *
 * Chỉ xét model có khai `BelongsToSchool` — model chưa khai thì bỏ qua, và `ModelScopedBySchoolTest`
 * canh để mọi model dùng trong route manager đều phải khai.
 *
 * `School` CỐ Ý không khai: màn hình Quản lý Trường vốn là việc xuyên trường, người vận hành phải
 * sửa/xóa được trường khác với trường đang làm việc. Nhóm route đó đã kẹp `role:super_admin`.
 */
class EnsureModelBelongsToWorkingSchool
{
    public function handle(Request $request, Closure $next): Response
    {
        $dangLam = WorkingSchool::id($request);

        foreach ($request->route()?->parameters() ?? [] as $param) {
            if (! $param instanceof BelongsToSchool) {
                continue;
            }

            $cua = $param->schoolId();

            // null = bản ghi mồ côi (mất quan hệ cha). Chặn: thà chặn nhầm còn hơn cho sửa nhầm.
            if ($cua === null || $dangLam === null || $cua !== (int) $dangLam) {
                abort(404);
            }
        }

        return $next($request);
    }
}
