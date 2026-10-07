<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OBE\ObeConfig;
use App\Support\WorkingSchool;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gắn "Trường đang làm việc" vào request (attribute workingSchoolId) cho toàn console Manager.
 * Nhờ đó controller chỉ cần đọc 1 dòng, và pagination/search/filter KHÔNG phải mang ?school_id.
 *
 * ĐỒNG THỜI đặt ngữ cảnh cho ObeConfig trong suốt request.
 *
 * Thiếu bước này thì mọi lời gọi `ObeConfig::…()` trong controller rơi về MẶC ĐỊNH TOÀN HỆ THỐNG,
 * bỏ qua tham số riêng của trường: trường khai chuyên cần 10% + giữa kỳ 40% + cuối kỳ 50% mà màn
 * hình vẫn ghi "quá trình 40% · cuối kỳ 60%", ngưỡng đạt CLO/môn cũng lấy nhầm. Số thì engine
 * tính đúng (engine tự bọc forSchool), nhưng phần GIẢI THÍCH trên màn hình lại nói khác — người
 * dùng đối chiếu hai chỗ sẽ tưởng hệ thống sai.
 */
class ResolveWorkingSchool
{
    public function handle(Request $request, Closure $next): Response
    {
        $schoolId = WorkingSchool::resolve($request);
        $request->attributes->set(WorkingSchool::ATTR, $schoolId);

        return ObeConfig::forSchool($schoolId, fn () => $next($request));
    }
}
