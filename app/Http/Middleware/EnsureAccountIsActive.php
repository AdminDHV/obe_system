<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tài khoản bị VÔ HIỆU HOÁ thì cắt phiên ngay, không đợi hết hạn.
 *
 * Màn hình Người dùng có nút "Vô hiệu hoá" ghi `is_active = false`, nhưng trước đây KHÔNG chỗ nào
 * đọc cờ đó — người bị khoá vẫn đăng nhập và làm việc bình thường. Nút bấm mà không có tác dụng
 * còn nguy hiểm hơn không có nút, vì người vận hành tin là đã khoá xong rồi.
 *
 * Chặn ở tầng request chứ không chỉ ở lúc đăng nhập: tình huống thật là phát hiện lộ mật khẩu
 * hoặc người nghỉ việc trong khi họ ĐANG mở phiên — phải đá ra ngay, không đợi phiên hết hạn.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Tài khoản đã bị vô hiệu hoá. Liên hệ quản trị viên.']);
        }

        return $next($request);
    }
}
