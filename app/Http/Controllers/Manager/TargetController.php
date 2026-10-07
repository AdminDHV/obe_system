<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Services\OBE\ObeConfig;
use App\Services\OBE\TargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Khai mục tiêu mức đạt (%) theo từng PLO (Feature A1). Mỏng — uỷ quyền TargetService.
 */
class TargetController extends Controller
{
    public function index(Request $request, TargetService $service): JsonResponse
    {
        $programId = $request->integer('program_id');
        if (!$programId) {
            return response()->json(['plos' => [], 'global_target' => ObeConfig::targetAttainmentPercent()]);
        }

        return response()->json([
            'plos' => $service->listForProgram($programId),
            'global_target' => ObeConfig::targetAttainmentPercent(),
        ]);
    }

    public function store(Request $request, TargetService $service): RedirectResponse
    {
        $validated = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'targets' => ['present', 'array'],
            'targets.*.plo_id' => ['required', 'integer'],
            'targets.*.target_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $service->upsertForProgram((int) $validated['program_id'], $validated['targets']);

        return redirect()->back()->with('success', 'Đã lưu mục tiêu mức đạt theo PLO.');
    }
}
