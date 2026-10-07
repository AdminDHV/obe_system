<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? array_merge($request->user()->toArray(), [
                    'roles' => $request->user()->getRoleNames(),
                ]) : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Ngữ cảnh "Trường đang làm việc" (tenant) dùng chung cho toàn console Manager.
            // Đặt tên riêng (workingSchools) để KHÔNG bị prop `schools` cấp-trang ghi đè.
            'workingSchools' => fn () => $request->user()
                ? \App\Support\WorkingSchool::switchableSchools($request->user())
                : [],
            'workingSchoolId' => fn () => $request->attributes->get(\App\Support\WorkingSchool::ATTR),
            // Tên trường đang làm việc, lấy theo ID ĐÃ CHỐT chứ không suy từ `workingSchools`:
            // trường bị tắt (is_active = false) không nằm trong danh sách chuyển được, nhưng người
            // dùng thuộc trường đó VẪN đang làm việc trong đó — suy từ danh sách thì header trống
            // trơn đúng lúc cần nói rõ nhất.
            'workingSchoolName' => fn () => ($id = $request->attributes->get(\App\Support\WorkingSchool::ATTR))
                ? \App\Models\School::withTrashed()->whereKey($id)->value('name')
                : null,
        ];
    }
}
