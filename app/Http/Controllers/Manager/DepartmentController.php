<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $departments = Department::withCount(['users', 'programs'])
            ->where('school_id', WorkingSchool::id($request))
            ->when($q = $request->get('q'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->through(fn($item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'lecturers_count' => $item->users_count,
                'programs_count' => $item->programs_count,
            ]);

        return Inertia::render('Manager/Departments/Index', [
            'departments' => $departments,
            'currentFilters' => $request->only(['q', 'page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('departments', 'code')->where('school_id', $sid)],
            'name' => 'required|string|max:255',
        ]);
        $validated['school_id'] = $sid;

        Department::create($validated);

        return redirect()->back()->with('success', 'Department created successfully.');
    }

    public function update(Department $department, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Mã unique trong TRƯỜNG của khoa (không đổi trường khi sửa).
            'code' => ['required', 'string', 'max:255', Rule::unique('departments', 'code')->where('school_id', $department->school_id)->ignore($department->id)],
            'name' => 'required|string|max:255',
        ]);

        $department->update($validated);

        return redirect()->back()->with('success', 'Department updated successfully.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        $department->delete();

        return redirect()->back()->with('success', 'Department deleted successfully.');
    }

    /**
     * Chi tiết MỘT khoa — mở từ nút "Xem" ở danh sách.
     *
     * Bản cũ không nhận tham số mà hiển thị khoa CỦA CHÍNH người đang đăng nhập (tàn dư của
     * portal tự phục vụ giảng viên đã gỡ). Nút "Xem" ở danh sách vẫn truyền uuid vào, nên bấm
     * khoa nào cũng ra 404 "Bạn chưa được phân công khoa/bộ môn" — người vận hành đâu có thuộc
     * khoa nào.
     */
    public function show(Request $request, Department $department): Response
    {
        abort_if((int) $department->school_id !== (int) WorkingSchool::id($request), 404);

        $department->load(['programs', 'users']);

        $programs = $department->programs->map(fn($p) => [
            'uuid' => $p->uuid,
            'code' => $p->code,
            'name' => $p->name,
        ]);

        $lecturers = $department->users->map(fn($u) => [
            'uuid' => $u->uuid,
            'staff_code' => $u->staff_code,
            'full_name' => $u->full_name,
            'email' => $u->email,
            'phone' => $u->phone,
            'position' => $u->position,
        ]);

        return Inertia::render('Manager/Department/Show', [
            'department' => [
                'code' => $department->code,
                'name' => $department->name,
            ],
            'programs' => $programs,
            'lecturers' => $lecturers,
        ]);
    }
}
