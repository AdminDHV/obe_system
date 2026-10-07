<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\Rubric;
use App\Support\WorkingSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RubricController extends Controller
{
    public function index(Request $request): Response
    {
        $sid = WorkingSchool::id($request);
        $rubrics = Rubric::with('assessment.syllabus.course')
            ->withCount('criteria')
            ->whereHas('assessment.syllabus.course', fn($q) => $q->where('school_id', $sid))
            ->when($q = $request->get('q'), fn($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($assessmentId = $request->get('assessment_id'), fn($query, $id) => $query->where('assessment_id', $id))
            ->orderBy('name')
            ->paginate(15)
            ->through(fn($item) => [
                'uuid' => $item->uuid,
                'name' => $item->name,
                'criteria_count' => $item->criteria_count,
                'assessment' => $item->assessment ? [
                    'id' => $item->assessment->id,
                    'name' => $item->assessment->name,
                    'syllabus' => $item->assessment->syllabus ? [
                        'version' => $item->assessment->syllabus->version,
                        'course' => [
                            'code' => $item->assessment->syllabus->course->code ?? '',
                            'name' => $item->assessment->syllabus->course->name ?? '',
                        ],
                    ] : null,
                ] : null,
            ]);

        // Only rubric-based assessments are eligible to own a rubric.
        $assessments = Assessment::with('syllabus.course')
            ->where('is_rubric_based', true)
            ->whereHas('syllabus.course', fn($q) => $q->where('school_id', $sid))
            ->orderBy('name')
            ->get()
            ->map(fn($a) => [
                'id' => $a->id,
                'label' => ($a->syllabus?->course?->code ?? '') . ' · ' . $a->name,
                'has_rubric' => $a->rubric()->exists(),
            ]);

        return Inertia::render('Manager/Rubrics/Index', [
            'rubrics' => $rubrics,
            'assessments' => $assessments,
            'currentFilters' => $request->only(['q', 'assessment_id', 'page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'assessment_id' => ['required', 'exists:assessments,id', $this->assessmentInSchoolRule(WorkingSchool::id($request))],
            'name' => 'required|string|max:255',
        ]);

        // An assessment can own at most one rubric (HasOne relationship).
        if (Rubric::where('assessment_id', $validated['assessment_id'])->exists()) {
            return redirect()->back()->with('error', 'Đầu điểm này đã có rubric. Mỗi đầu điểm chỉ có một rubric.');
        }

        Rubric::create($validated);

        return redirect()->back()->with('success', 'Tạo rubric thành công.');
    }

    public function show(Rubric $rubric): Response
    {
        $rubric->load(['assessment.syllabus.course', 'criteria.clo']);

        // CLO options are scoped to the syllabus that owns this rubric's assessment.
        $syllabusId = $rubric->assessment?->syllabus_id;
        $clos = Clo::when($syllabusId, fn($query) => $query->where('syllabus_id', $syllabusId))
            ->orderBy('code')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'description' => $c->description,
            ]);

        return Inertia::render('Manager/Rubrics/Show', [
            'rubric' => [
                'uuid' => $rubric->uuid,
                'id' => $rubric->id,
                'name' => $rubric->name,
                'assessment' => $rubric->assessment ? [
                    'name' => $rubric->assessment->name,
                    'weight_percent' => $rubric->assessment->weight_percent,
                    'syllabus' => $rubric->assessment->syllabus ? [
                        'version' => $rubric->assessment->syllabus->version,
                        'course' => [
                            'code' => $rubric->assessment->syllabus->course->code ?? '',
                            'name' => $rubric->assessment->syllabus->course->name ?? '',
                        ],
                    ] : null,
                ] : null,
                'criteria' => $rubric->criteria->map(fn($c) => [
                    'uuid' => $c->uuid,
                    'description' => $c->description,
                    'max_score' => $c->max_score,
                    'clo' => $c->clo ? [
                        'id' => $c->clo->id,
                        'code' => $c->clo->code,
                        'description' => $c->clo->description,
                    ] : null,
                ])->values(),
            ],
            'clos' => $clos,
        ]);
    }

    public function update(Rubric $rubric, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $rubric->update($validated);

        return redirect()->back()->with('success', 'Cập nhật rubric thành công.');
    }

    public function destroy(Rubric $rubric): RedirectResponse
    {
        $rubric->delete();

        return redirect()->back()->with('success', 'Xóa rubric thành công.');
    }

    /** Đầu điểm (assessment) phải thuộc môn của trường đang làm việc. */
    private function assessmentInSchoolRule(?int $sid): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($sid): void {
            $ok = Assessment::whereKey($value)
                ->whereHas('syllabus.course', fn($q) => $q->where('school_id', $sid))->exists();
            if (! $ok) {
                $fail('Đầu điểm không thuộc trường đang làm việc.');
            }
        };
    }
}
