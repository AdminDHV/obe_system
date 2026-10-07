<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    public function index(Request $request): Response
    {
        $courses = Course::with('programs')
            ->where('school_id', WorkingSchool::id($request))
            ->when($q = $request->get('q'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('code')
            ->paginate(15)
            ->through(fn($item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'credits' => $item->credits,
                'programs' => $item->programs->map(fn($p) => [
                    'code' => $p->code,
                    'name' => $p->name,
                ]),
            ]);

        return Inertia::render('Manager/Courses/Index', [
            'courses' => $courses,
            'currentFilters' => $request->only(['q', 'page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('courses', 'code')->where('school_id', $sid)],
            'name' => 'required|string|max:255',
            'credits' => 'required|integer|min:1',
        ]);
        $validated['school_id'] = $sid;

        Course::create($validated);

        return redirect()->back()->with('success', 'Course created successfully.');
    }

    public function update(Course $course, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('courses', 'code')->where('school_id', $course->school_id)->ignore($course->id)],
            'name' => 'required|string|max:255',
            'credits' => 'required|integer|min:1',
        ]);

        $course->update($validated);

        return redirect()->back()->with('success', 'Course updated successfully.');
    }

    public function destroy(Course $course): RedirectResponse
    {
        $course->delete();

        return redirect()->back()->with('success', 'Course deleted successfully.');
    }
}
