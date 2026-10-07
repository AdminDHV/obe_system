<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // DEPLOY.md khai health check là GET /up nhưng route này chưa từng được đăng ký
        // ⇒ giám sát gọi vào chỉ nhận 404 và tưởng app chết.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sau reverse proxy (nginx/HTTPS) → tin header X-Forwarded-* để cấp secure-cookie
        // và sinh URL https đúng. An toàn vì app chỉ nhận request qua proxy nội bộ.
        $middleware->trustProxies(at: '*');

        // KHÔNG thêm AddLinkHeadersForPreloadedAssets: middleware này gắn header `Link` liệt kê
        // mọi asset Vite để trình duyệt preload. Trang nhiều chunk (Trung tâm đo lường) cho ra
        // header `Link` ~2.6 KB; cộng Set-Cookie ~0.9 KB là vượt `proxy_buffer_size` mặc định 4 KB
        // của nginx, và nginx trả 502 "upstream sent too big header" — app vẫn 200, chỉ proxy gãy.
        // Preload chỉ là tối ưu nhỏ; trang vẫn tải đủ asset qua thẻ <script>/<link> bình thường.
        // Đổi lấy việc console không sập là đáng, và không phải phụ thuộc cấu hình từng server.
        $middleware->web(append: [
            // Cắt phiên của tài khoản bị vô hiệu hoá — đặt TRƯỚC Inertia để trang không kịp dựng.
            EnsureAccountIsActive::class,
            HandleInertiaRequests::class,
        ]);

        // Cookie "Trường đang làm việc" do frontend đặt (document.cookie) → KHÔNG mã hoá,
        // để server đọc raw. Nếu mã hoá, cookie client-set sẽ bị EncryptCookies loại bỏ.
        $middleware->encryptCookies(except: ['obe_working_school']);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
