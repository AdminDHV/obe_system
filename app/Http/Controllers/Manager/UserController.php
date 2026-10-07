<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreUserRequest;
use App\Http\Requests\Manager\UpdateUserRequest;
use App\Models\Department;
use App\Models\User;
use App\Services\Manager\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly UserAccountService $accounts)
    {
    }

    public function index(Request $request): Response
    {
        $users = User::with('department')
            ->when($q = $request->get('q'), function ($query, $search) {
                $query->where(function ($qq) use ($search) {
                    $qq->where('full_name', 'ilike', "%{$search}%")
                        ->orWhere('staff_code', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
            })
            ->when($role = $request->get('role'), fn($query, $r) => $query->role($r))
            ->when($request->filled('is_active'), fn($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($departmentId = $request->get('department_id'), fn($query, $id) => $query->where('department_id', $id))
            ->orderBy('full_name')
            ->paginate(15)
            ->through(fn(User $item) => [
                'uuid' => $item->uuid,
                'staff_code' => $item->staff_code,
                'full_name' => $item->full_name,
                'email' => $item->email,
                'position' => $item->position,
                'phone' => $item->phone,
                'is_active' => $item->is_active,
                'department_id' => $item->department_id,
                'department' => $item->department ? ['name' => $item->department->name] : null,
                'roles' => $item->getRoleNames(),
            ]);

        return Inertia::render('Manager/Users/Index', [
            'users' => $users,
            'departments' => Department::select('id', 'name')->orderBy('name')->get(),
            'availableRoles' => Role::orderBy('name')->pluck('name'),
            'currentFilters' => $request->only(['q', 'role', 'is_active', 'department_id', 'page']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $roles = $data['roles'];
        unset($data['roles']);
        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = $data['is_active'] ?? true;

        $user = User::create($data);
        $user->syncRoles($roles);

        return redirect()->back()->with('success', 'Đã tạo tài khoản người dùng.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $roles = $data['roles'];
        unset($data['roles']);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        try {
            // Guard role changes (e.g. demoting the last super_admin) before persisting.
            $this->accounts->updateRoles($user, $roles);
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $user->update($data);

        return redirect()->back()->with('success', 'Đã cập nhật tài khoản người dùng.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        try {
            $this->accounts->setActive($user, !$user->is_active);
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Đã cập nhật trạng thái tài khoản.');
    }

    public function destroy(User $user): RedirectResponse
    {
        try {
            $this->accounts->delete($user);
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Đã xóa tài khoản người dùng.');
    }
}
