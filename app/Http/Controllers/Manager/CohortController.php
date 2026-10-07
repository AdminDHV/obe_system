<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Cohort;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CohortController extends Controller
{
    public function index(Request $request): Response
    {
        $sid = WorkingSchool::id($request);
        $cohorts = Cohort::with('program')
            ->withCount('students')
            ->where('school_id', $sid)
            ->when($q = $request->get('q'), fn($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($programId = $request->get('program_id'), fn($query, $id) => $query->where('program_id', $id))
            ->orderBy('name')
            ->paginate(15)
            ->through(fn($item) => [
                'uuid' => $item->uuid,
                'name' => $item->name,
                'start_year' => $item->start_year,
                'program_id' => $item->program_id,
                'program' => $item->program ? ['code' => $item->program->code, 'name' => $item->program->name] : null,
                'students_count' => $item->students_count,
            ]);

        $programs = \App\Models\Program::where('school_id', $sid)->select('id', 'code', 'name')->orderBy('name')->get();

        return Inertia::render('Manager/Cohorts/Index', [
            'cohorts' => $cohorts,
            'programs' => $programs,
            'currentFilters' => $request->only(['q', 'program_id', 'page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sid = WorkingSchool::id($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_year' => 'required|integer',
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $sid)],
        ]);
        $validated['school_id'] = $sid;

        Cohort::create($validated);

        return redirect()->back()->with('success', 'Cohort created successfully.');
    }

    public function update(Cohort $cohort, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_year' => 'required|integer',
            'program_id' => ['required', Rule::exists('programs', 'id')->where('school_id', $cohort->school_id)],
        ]);

        $cohort->update($validated);

        return redirect()->back()->with('success', 'Cohort updated successfully.');
    }

    public function destroy(Cohort $cohort): RedirectResponse
    {
        $cohort->delete();

        return redirect()->back()->with('success', 'Cohort deleted successfully.');
    }
}
