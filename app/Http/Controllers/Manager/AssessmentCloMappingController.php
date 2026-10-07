<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentCloMapping;
use App\Models\Clo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AssessmentCloMappingController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'assessment_id' => ['required', 'exists:assessments,id'],
            'clo_id' => ['required', 'exists:clos,id', $this->cloInSyllabusRule($request)],
            'weight_in_assessment' => ['required', 'numeric', 'between:0,100'],
        ]);

        // Một thành phần chỉ map 1 lần tới mỗi CLO.
        if (AssessmentCloMapping::where('assessment_id', $validated['assessment_id'])->where('clo_id', $validated['clo_id'])->exists()) {
            return redirect()->back()->with('error', 'CLO này đã được map cho thành phần đánh giá.');
        }

        AssessmentCloMapping::create($validated);

        return redirect()->back()->with('success', 'Đã map CLO vào thành phần đánh giá.');
    }

    public function update(AssessmentCloMapping $mapping, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'clo_id' => ['required', 'exists:clos,id'],
            'weight_in_assessment' => ['required', 'numeric', 'between:0,100'],
        ]);

        $mapping->update($validated);

        return redirect()->back()->with('success', 'Đã cập nhật map CLO.');
    }

    public function destroy(AssessmentCloMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return redirect()->back()->with('success', 'Đã gỡ map CLO.');
    }

    /** CLO phải thuộc đúng đề cương của thành phần đánh giá. */
    private function cloInSyllabusRule(Request $request): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($request): void {
            $assessment = Assessment::find($request->input('assessment_id'));
            if (!$assessment) {
                return;
            }
            $ok = Clo::whereKey($value)->where('syllabus_id', $assessment->syllabus_id)->exists();
            if (!$ok) {
                $fail('CLO không thuộc đề cương của thành phần đánh giá này.');
            }
        };
    }
}
