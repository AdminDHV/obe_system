<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vai trò CHỈ XEM (`viewer` — cố vấn học tập, ban giám hiệu, phòng đảm bảo chất lượng) không được
 * ghi bất cứ thứ gì.
 *
 * Vì sao chặn theo METHOD chứ không liệt kê route: console có hơn 130 route, trong đó hơn 80 route
 * ghi. Liệt kê tay thì chỉ cần một người thêm route mới mà quên là thủng. Chặn ở đây thì route
 * viết sau cũng tự động an toàn — muốn cho viewer ghi phải cố ý mở, không thể lọt do sơ ý.
 *
 * Ẩn nút trên giao diện KHÔNG phải phân quyền; đây mới là chỗ chặn thật.
 */
class EnsureReadOnlyRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasRole('viewer') && ! $user->hasRole('super_admin')
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            abort(403, 'Tài khoản chỉ có quyền xem.');
        }

        return $next($request);
    }
}
