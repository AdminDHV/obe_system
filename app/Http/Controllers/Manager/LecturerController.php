<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LecturerController extends Controller
{
    /** Khớp LECTURER_STATUS_OPTIONS ở resources/js/lib/status.js. */
    private const EMPLOYMENT_STATUSES = ['full_time', 'part_time', 'inactive'];

    public function index(Request $request): Response
    {
        $sid = WorkingSchool::id($request);
        $lecturers = User::role('lecturer')
            ->with('department')
            ->whereHas('department', fn($q) => $q->where('school_id', $sid))
            ->when($q = $request->get('q'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('staff_code', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($departmentId = $request->get('department_id'), fn($query, $id) => $query->where('department_id', $id))
            ->when($employmentStatus = $request->get('employment_status'), fn($query, $status) => $query->where('employment_status', $status))
            ->orderBy('full_name')
            ->paginate(15)
            ->through(fn($item) => [
                'uuid' => $item->uuid,
                'staff_code' => $item->staff_code,
                'full_name' => $item->full_name,
                'position' => $item->position,
                'email' => $item->email,
                'phone' => $item->phone,
                'employment_status' => $item->employment_status,
                'department_id' => $item->department_id,
                'department' => $item->department ? ['name' => $item->department->name] : null,
            ]);

        $departments = \App\Models\Department::where('school_id', $sid)->select('id', 'code', 'name')->orderBy('name')->get();

        return Inertia::render('Manager/Lecturers/Index', [
            'lecturers' => $lecturers,
            'departments' => $departments,
            'currentFilters' => $request->only(['q', 'department_id', 'employment_status', 'page']),
        ]);
    }

    /**
     * Giảng viên có thuộc TRƯỜNG ĐANG LÀM VIỆC không.
     *
     * `show`/`update`/`destroy` trước đây chỉ kiểm "có phải giảng viên không", không kiểm trường —
     * nên đổi uuid trên URL là xem, SỬA và XÓA được giảng viên của trường khác. Riêng `update` còn
     * validate khoa theo trường của CHÍNH NGƯỜI BỊ SỬA thay vì trường đang làm việc, tức là càng
     * đi sâu càng lệch khỏi phạm vi.
     *
     * Lấy trường theo KHOA của giảng viên để khớp đúng bộ lọc ở `index()` — mở được đúng những
     * người nhìn thấy trong danh sách. Chưa gán khoa thì rơi về `users.school_id`.
     */
    private function assertSameSchool(User $user, Request $request): void
    {
        $cua = $user->department?->school_id ?? $user->school_id;

        abort_if($cua === null || (int) $cua !== (int) WorkingSchool::id($request), 404);
    }

    public function show(Request $request, User $user): Response
    {
        abort_unless($user->hasRole('lecturer'), 404);
        $this->assertSameSchool($user, $request);

        $user->load(['department', 'courseClasses.syllabus.course']);

        $courseClasses = $user->courseClasses->map(function ($class) {
            $studentsCount = $class->enrollments()->count();
            return [
                'uuid' => $class->uuid,
                'class_code' => $class->class_code,
                'course_name' => $class->syllabus?->course?->name ?? '',
                'course_code' => $class->syllabus?->course?->code ?? '',
                'semester' => $class->semester,
                'academic_year' => $class->academic_year,
                'student_count' => $studentsCount,
            ];
        });

        return Inertia::render('Manager/Lecturers/Show', [
            'lecturer' => [
                'uuid' => $user->uuid,
                'staff_code' => $user->staff_code,
                'full_name' => $user->full_name,
                'position' => $user->position,
                'email' => $user->email,
                'phone' => $user->phone,
                'employment_status' => $user->employment_status,
                'department' => $user->department ? ['name' => $user->department->name] : null,
            ],
            'classes' => $courseClasses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // `position`/`phone` là TÙY CHỌN: form không đánh dấu bắt buộc, và giảng viên "kỹ thuật"
        // (tài khoản chỉ để mang lớp khi import khung) thì không có chức vụ/số điện thoại.
        // Trước đây backend bắt buộc 2 field này → bấm Lưu là fail 422 mà form không hiện lỗi.
        $validated = $request->validate([
            'department_id' => ['required', Rule::exists('departments', 'id')->where('school_id', WorkingSchool::id($request))],
            'staff_code' => ['required', 'string', 'max:255',
                Rule::unique('users', 'staff_code')->where('school_id', WorkingSchool::id($request))],
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'position' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            // Ô "Trạng thái" ở form vẫn gửi lên nhưng trước đây KHÔNG có trong rule → bị bỏ im lặng.
            'employment_status' => ['nullable', Rule::in(self::EMPLOYMENT_STATUSES)],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);
        $user->assignRole('lecturer');

        return redirect()->back()->with('success', 'Đã tạo giảng viên.');
    }

    public function update(User $user, Request $request): RedirectResponse
    {
        abort_unless($user->hasRole('lecturer'), 404);
        $this->assertSameSchool($user, $request);

        $validated = $request->validate([
            'department_id' => ['required', Rule::exists('departments', 'id')->where('school_id', WorkingSchool::id($request))],
            'staff_code' => ['required', 'string', 'max:255',
                Rule::unique('users', 'staff_code')->where('school_id', WorkingSchool::id($request))->ignore($user->id)],
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'position' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'employment_status' => ['nullable', Rule::in(self::EMPLOYMENT_STATUSES)],
        ]);

        if ($validated['password'] ?? false) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return redirect()->back()->with('success', 'Lecturer updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->hasRole('lecturer'), 404);
        $this->assertSameSchool($user, $request);

        $user->delete();

        return redirect()->back()->with('success', 'Lecturer deleted successfully.');
    }
}
