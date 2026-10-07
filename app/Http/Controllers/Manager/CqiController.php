<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\CqiAction;
use App\Services\OBE\CqiService;
use App\Services\OBE\ObeAttainmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vòng cải tiến liên tục (CQI/PDCA) — Feature A3. Mỏng, uỷ quyền CqiService.
 */
class CqiController extends Controller
{
    public function index(Request $request, CqiService $cqi, ObeAttainmentService $attainment): JsonResponse
    {
        $programId = $request->integer('program_id');
        $cohortId = $request->integer('cohort_id') ?: null;
        if (!$programId) {
            return response()->json(['actions' => [], 'missed_count' => 0, 'summary' => null]);
        }

        // Tóm tắt trạng thái PLO để panel giải thích rõ vì sao có/không tạo được việc:
        // đã đo bao nhiêu, đạt/chưa đạt, còn bao nhiêu CHƯA ĐO (attainment null).
        $plos = collect($attainment->ploAttainment($programId, $cohortId)['plos']);
        $measured = $plos->filter(fn ($r) => $r['attainment_percent'] !== null);
        $summary = [
            'total' => $plos->count(),
            'measured' => $measured->count(),
            'met' => $measured->filter(fn ($r) => $r['met_target'])->count(),
            'unmet' => $measured->filter(fn ($r) => !$r['met_target'])->count(),
            'unmeasured' => $plos->count() - $measured->count(),
        ];

        return response()->json([
            'actions' => $cqi->listWithCurrent($programId, $cohortId),
            'missed_count' => $summary['unmet'],
            'summary' => $summary,
        ]);
    }

    public function seed(Request $request, CqiService $cqi): RedirectResponse
    {
        $validated = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'cohort_id' => ['nullable', 'integer', 'exists:cohorts,id'],
        ]);

        $n = $cqi->seedFromAttainment((int) $validated['program_id'], $validated['cohort_id'] ?? null);

        return redirect()->back()->with('success', $n > 0
            ? "Đã tạo {$n} việc cải tiến cho các PLO chưa đạt."
            : 'Chưa tạo việc nào — xem dòng trạng thái PLO ở panel (chưa đo / đã đạt / hoặc PLO chưa đạt đã có việc đang mở).');
    }

    public function update(Request $request, CqiAction $cqiAction): RedirectResponse
    {
        $validated = $request->validate([
            'finding' => ['nullable', 'string'],
            'root_cause' => ['nullable', 'string'],
            'action_plan' => ['nullable', 'string'],
            'owner' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:open,in_progress,done,cancelled'],
        ]);

        $cqiAction->update($validated);

        return redirect()->back()->with('success', 'Đã cập nhật việc cải tiến.');
    }
}
