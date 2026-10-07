<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProgramController extends Controller
{
    public function index(Request $request): Response
    {
        $sid = WorkingSchool::id($request);
        $programs = Program::with('department')
            ->withCount(['courses', 'plos'])
            ->where('school_id', $sid)
            ->when($q = $request->get('q'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($departmentId = $request->get('department_id'), fn($query, $id) => $query->where('department_id', $id))
            ->orderBy('name')
            ->paginate(15)
            ->through(fn($item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'required_credits' => $item->required_credits,
                'department_id' => $item->department_id,
                'department' => $item->department ? ['name' => $item->department->name] : null,
                'courses_count' => $item->courses_count,
                'plos_count' => $item->plos_count,
            ]);

        $departments = \App\Models\Department::where('school_id', $sid)->select('id', 'code', 'name')->orderBy('name')->get();

        return Inertia::render('Manager/Programs/Index', [
            'programs' => $programs,
            'departments' => $departments,
            'currentFilters' => $request->only(['q', 'department_id', 'page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('programs', 'code')->where('school_id', $sid)],
            'name' => 'required|string|max:255',
            'department_id' => ['required', Rule::exists('departments', 'id')->where('school_id', $sid)],
            'required_credits' => ['nullable', 'integer', 'min:1', 'max:400'],
        ]);
        $validated['school_id'] = $sid;

        Program::create($validated);

        return redirect()->back()->with('success', 'Đã tạo ngành.');
    }

    public function update(Program $program, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('programs', 'code')->where('school_id', $program->school_id)->ignore($program->id)],
            'name' => 'required|string|max:255',
            'department_id' => ['required', Rule::exists('departments', 'id')->where('school_id', $program->school_id)],
            'required_credits' => ['nullable', 'integer', 'min:1', 'max:400'],
        ]);

        $program->update($validated);

        return redirect()->back()->with('success', 'Đã cập nhật ngành.');
    }

    public function destroy(Program $program): RedirectResponse
    {
        $program->delete();

        return redirect()->back()->with('success', 'Đã xóa ngành.');
    }

    public function show(Program $program): Response
    {
        $user = Auth::user();

        if ($user->hasRole('lecturer') && !$user->hasRole('super_admin')) {
            abort_if($program->department_id !== $user->department_id, 403, 'Bạn không có quyền xem ngành này.');
        }

        $program->load(['courses', 'plos']);

        $courses = $program->courses->map(fn($c) => [
            'code' => $c->code,
            'name' => $c->name,
            'credits' => $c->credits,
        ]);

        $plos = $program->plos->map(fn($p) => [
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
        ]);

        return Inertia::render('Manager/Programs/Show', [
            'program' => [
                'code' => $program->code,
                'name' => $program->name,
            ],
            'courses' => $courses,
            'plos' => $plos,
        ]);
    }
}
