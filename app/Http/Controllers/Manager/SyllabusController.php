<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\CourseSyllabus;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SyllabusController extends Controller
{
    public function index(Request $request): Response
    {
        $sid = WorkingSchool::id($request);
        $syllabi = CourseSyllabus::with('course')
            ->withCount(['clos', 'assessments'])
            ->whereHas('course', fn($q) => $q->where('school_id', $sid))
            ->when($q = $request->get('q'), function ($query, $search) {
                $query->whereHas('course', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($courseId = $request->get('course_id'), fn($query, $id) => $query->where('course_id', $id))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn($item) => [
                'uuid' => $item->uuid,
                'course_id' => $item->course_id,
                'course' => $item->course ? [
                    'code' => $item->course->code,
                    'name' => $item->course->name,
                ] : null,
                'version' => $item->version,
                'is_active' => $item->is_active,
                'clos_count' => $item->clos_count,
                'assessments_count' => $item->assessments_count,
            ]);

        $courses = \App\Models\Course::where('school_id', $sid)->select('id', 'code', 'name')->orderBy('code')->get();

        return Inertia::render('Manager/Syllabi/Index', [
            'syllabi' => $syllabi,
            'courses' => $courses,
            'currentFilters' => $request->only(['q', 'course_id', 'page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', Rule::exists('courses', 'id')->where('school_id', WorkingSchool::id($request))],
            'version' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        CourseSyllabus::create($validated);

        return redirect()->back()->with('success', 'Syllabus created successfully.');
    }

    public function update(CourseSyllabus $syllabus, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', Rule::exists('courses', 'id')->where('school_id', WorkingSchool::id($request))],
            'version' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $syllabus->update($validated);

        return redirect()->back()->with('success', 'Syllabus updated successfully.');
    }

    public function destroy(CourseSyllabus $syllabus): RedirectResponse
    {
        $syllabus->delete();

        return redirect()->back()->with('success', 'Syllabus deleted successfully.');
    }
}
