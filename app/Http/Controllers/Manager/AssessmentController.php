<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CourseSyllabus;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentController extends Controller
{
    public function index(Request $request): Response
    {
        $sid = WorkingSchool::id($request);
        $assessments = Assessment::with(['syllabus.course', 'cloMappings', 'rubric.criteria'])
            ->whereHas('syllabus.course', fn($q) => $q->where('school_id', $sid))
            ->when($q = $request->get('q'), fn($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($syllabusId = $request->get('syllabus_id'), fn($query, $id) => $query->where('syllabus_id', $id))
            ->orderBy('name')
            ->paginate(15)
            ->through(fn($item) => [
                'uuid' => $item->uuid,
                'name' => $item->name,
                'assessment_category' => $item->assessment_category,
                'weight_percent' => $item->weight_percent,
                'is_rubric_based' => $item->is_rubric_based,
                'syllabus_id' => $item->syllabus_id,
                'mapped_clo_count' => $item->mappedCloIds()->count(),
                'syllabus' => $item->syllabus ? [
                    'version' => $item->syllabus->version,
                    'course' => [
                        'code' => $item->syllabus->course->code ?? '',
                        'name' => $item->syllabus->course->name ?? '',
                    ],
                ] : null,
            ]);

        $syllabi = \App\Models\CourseSyllabus::with('course')
            ->whereHas('course', fn($q) => $q->where('school_id', $sid))
            ->select('id', 'version', 'course_id')
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'label' => ($s->course?->code ?? '') . ' - v' . $s->version,
            ]);

        return Inertia::render('Manager/Assessments/Index', [
            'assessments' => $assessments,
            'syllabi' => $syllabi,
            'currentFilters' => $request->only(['q', 'syllabus_id', 'page']),
        ]);
    }

    public function show(Assessment $assessment): Response
    {
        $assessment->load(['syllabus.course', 'cloMappings.clo', 'rubric.criteria.clo']);

        // CLO suy từ tiêu chí Rubric (chỉ đọc) nếu là thành phần rubric-based.
        $rubricClos = $assessment->is_rubric_based && $assessment->rubric
            ? $assessment->rubric->criteria
                ->map(fn($c) => $c->clo ? ['code' => $c->clo->code, 'description' => $c->clo->description] : null)
                ->filter()
                ->unique('code')
                ->values()
            : collect();

        return Inertia::render('Manager/Assessments/Show', [
            'assessment' => [
                'uuid' => $assessment->uuid,
                'id' => $assessment->id,
                'name' => $assessment->name,
                'assessment_category' => $assessment->assessment_category,
                'weight_percent' => $assessment->weight_percent,
                'is_rubric_based' => $assessment->is_rubric_based,
                'rubric_uuid' => $assessment->rubric?->uuid,
                'syllabus' => $assessment->syllabus ? [
                    'version' => $assessment->syllabus->version,
                    'course' => [
                        'code' => $assessment->syllabus->course->code ?? '',
                        'name' => $assessment->syllabus->course->name ?? '',
                    ],
                ] : null,
                'mappings' => $assessment->cloMappings->map(fn($m) => [
                    'uuid' => $m->uuid,
                    'weight_in_assessment' => $m->weight_in_assessment,
                    'clo' => $m->clo ? ['id' => $m->clo->id, 'code' => $m->clo->code, 'description' => $m->clo->description] : null,
                ])->values(),
                'rubric_clos' => $rubricClos,
            ],
            'clos' => Clo::where('syllabus_id', $assessment->syllabus_id)
                ->orderBy('code')
                ->get()
                ->map(fn($c) => ['id' => $c->id, 'code' => $c->code, 'description' => $c->description]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_id' => ['required', 'exists:course_syllabus,id', $this->syllabusInSchoolRule(WorkingSchool::id($request))],
            'name' => 'required|string|max:255',
            'assessment_category' => 'required|string|max:255',
            'weight_percent' => 'required|numeric|between:0,100',
            'is_rubric_based' => 'boolean',
        ]);

        Assessment::create($validated);

        return redirect()->back()->with('success', 'Assessment created successfully.');
    }

    public function update(Assessment $assessment, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_id' => ['required', 'exists:course_syllabus,id', $this->syllabusInSchoolRule(WorkingSchool::id($request))],
            'name' => 'required|string|max:255',
            'assessment_category' => 'required|string|max:255',
            'weight_percent' => 'required|numeric|between:0,100',
            'is_rubric_based' => 'boolean',
        ]);

        $assessment->update($validated);

        return redirect()->back()->with('success', 'Assessment updated successfully.');
    }

    /** Đề cương của bài đánh giá phải thuộc môn của trường đang làm việc. */
    private function syllabusInSchoolRule(?int $sid): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($sid): void {
            $ok = CourseSyllabus::whereKey($value)
                ->whereHas('course', fn($q) => $q->where('school_id', $sid))->exists();
            if (! $ok) {
                $fail('Đề cương không thuộc trường đang làm việc.');
            }
        };
    }

    public function destroy(Assessment $assessment): RedirectResponse
    {
        $assessment->delete();

        return redirect()->back()->with('success', 'Assessment deleted successfully.');
    }
}
