<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\MappingScaleWeight;
use App\Models\School;
use App\Models\SystemSetting;
use App\Services\OBE\ObeConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolController extends Controller
{
    public function index(Request $request): Response
    {
        $schools = School::withCount(['programs', 'students', 'courseClasses'])
            ->when($q = $request->get('q'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%")
                      ->orWhere('short_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->through(fn (School $s) => [
                'uuid' => $s->uuid,
                'code' => $s->code,
                'name' => $s->name,
                'short_name' => $s->short_name,
                'contact_name' => $s->contact_name,
                'contact_email' => $s->contact_email,
                'contact_phone' => $s->contact_phone,
                'is_active' => $s->is_active,
                'obe_method' => $s->obe_method,
                // Tham số đo riêng của trường (rỗng = dùng mặc định dùng chung).
                'obe_settings' => SystemSetting::where('school_id', $s->id)->pluck('value', 'key'),
                'scale_weights' => MappingScaleWeight::where('school_id', $s->id)->pluck('weight_percent', 'level'),
                'programs_count' => $s->programs_count,
                'students_count' => $s->students_count,
                'classes_count' => $s->course_classes_count,
            ]);

        return Inertia::render('Manager/Schools/Index', [
            'schools' => $schools,
            'currentFilters' => $request->only(['q', 'page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateSchool($request);
        School::create($validated);

        return redirect()->back()->with('success', 'Đã tạo trường.');
    }

    public function update(School $school, Request $request): RedirectResponse
    {
        $validated = $this->validateSchool($request, $school->id);
        $school->update($validated);

        return redirect()->back()->with('success', 'Đã cập nhật trường.');
    }

    /**
     * Khai THAM SỐ ĐO RIÊNG cho một trường. Bỏ trống một ô ⇒ xoá dòng riêng ⇒ trường đó quay về
     * dùng mặc định dùng chung (`school_id NULL`), chứ không phải lưu giá trị 0.
     */
    public function updateObeSettings(School $school, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings' => ['array'],
            'settings.*' => ['nullable', 'numeric'],
            'scale_weights' => ['array'],
            'scale_weights.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $allowed = [
            'obe.clo_pass_threshold', 'obe.plo_attainment_ratio_pass', 'obe.plo_pass_threshold',
            'obe.target_attainment_percent', 'obe.course_pass_threshold',
            'obe.measurement_min_completion_percent', 'obe.process_weight', 'obe.final_weight',
        ];

        foreach ($validated['settings'] ?? [] as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;   // chỉ cho chỉnh tham số đo, không mở cửa cho mọi khoá cấu hình
            }
            if ($value === null || $value === '') {
                SystemSetting::where('school_id', $school->id)->where('key', $key)->delete();
                continue;
            }
            SystemSetting::updateOrCreate(
                ['school_id' => $school->id, 'key' => $key],
                ['value' => (string) $value],
            );
        }

        foreach ($validated['scale_weights'] ?? [] as $level => $value) {
            if (! in_array($level, ['I', 'R', 'M', 'A'], true)) {
                continue;
            }
            if ($value === null || $value === '') {
                MappingScaleWeight::where('school_id', $school->id)->where('level', $level)->delete();
                continue;
            }
            MappingScaleWeight::updateOrCreate(
                ['school_id' => $school->id, 'level' => $level],
                ['weight_percent' => (float) $value],
            );
        }

        ObeConfig::flush();

        return redirect()->back()->with(
            'success',
            'Đã lưu tham số đo riêng của trường. Bấm "Tính lại" để áp dụng cho kết quả đã đo.',
        );
    }

    public function destroy(School $school): RedirectResponse
    {
        // Guard: không xóa trường còn dữ liệu (FK school_id cascade → tránh xóa lan).
        // Muốn ngừng dùng → tắt "Đang hoạt động".
        if ($school->programs()->exists() || $school->students()->exists() || $school->courseClasses()->exists()) {
            return redirect()->back()->with('error', 'Không thể xóa trường đang có dữ liệu (ngành / sinh viên / lớp). Hãy tắt "Đang hoạt động" để ngừng sử dụng thay vì xóa.');
        }

        $school->delete();

        return redirect()->back()->with('success', 'Đã xóa trường.');
    }

    /** @return array<string,mixed> */
    private function validateSchool(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:schools,code' . ($ignoreId ? ',' . $ignoreId : '');

        return $request->validate([
            'code' => "required|string|max:50|{$unique}",
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:100',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'obe_method' => 'nullable|in:weighted_attainment,standard_average',
        ]);
    }
}
