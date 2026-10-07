<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\RubricCriteria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RubricCriteriaController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rubric_id' => 'required|exists:rubrics,id',
            'clo_id' => 'required|exists:clos,id',
            'max_score' => 'required|numeric|between:0,999.99',
            'description' => 'required|string',
        ]);

        RubricCriteria::create($validated);

        return redirect()->back()->with('success', 'Thêm tiêu chí thành công.');
    }

    public function update(RubricCriteria $criteria, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'clo_id' => 'required|exists:clos,id',
            'max_score' => 'required|numeric|between:0,999.99',
            'description' => 'required|string',
        ]);

        $criteria->update($validated);

        return redirect()->back()->with('success', 'Cập nhật tiêu chí thành công.');
    }

    public function destroy(RubricCriteria $criteria): RedirectResponse
    {
        $criteria->delete();

        return redirect()->back()->with('success', 'Xóa tiêu chí thành công.');
    }
}
