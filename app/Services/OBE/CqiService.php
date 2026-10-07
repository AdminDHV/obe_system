<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\CqiAction;
use App\Models\Program;

/**
 * Continuous improvement loop (CQI/PDCA): seeds improvement actions for PLOs that miss
 * their target, and compares the frozen "before" figure against current attainment.
 */
class CqiService
{
    public function __construct(private ObeAttainmentService $attainment) {}

    /**
     * Create an open action for each PLO below target. Idempotent: PLOs with an
     * open/in_progress action are skipped.
     *
     * @return int number of actions created
     */
    public function seedFromAttainment(int $programId, ?int $cohortId): int
    {
        $program = Program::find($programId);
        $schoolId = $program?->school_id;
        $att = $this->attainment->ploAttainment($programId, $cohortId);

        $created = 0;
        foreach ($att['plos'] as $r) {
            if ($r['attainment_percent'] === null || $r['met_target']) {
                continue; // not measured yet, or already on target
            }

            $exists = CqiAction::where('program_id', $programId)
                ->where('cohort_id', $cohortId)
                ->where('plo_id', $r['plo_id'])
                ->whereIn('status', ['open', 'in_progress'])
                ->exists();
            if ($exists) {
                continue;
            }

            CqiAction::create([
                'school_id' => $schoolId,
                'program_id' => $programId,
                'cohort_id' => $cohortId,
                'plo_id' => $r['plo_id'],
                'attainment_percent' => $r['attainment_percent'],
                'target_percent' => $r['target_percent'],
                'status' => 'open',
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Improvement actions of a program (+cohort) with current attainment for before/after
     * comparison. Returns plain arrays for the frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWithCurrent(int $programId, ?int $cohortId): array
    {
        // Current attainment keyed by plo_id.
        $current = [];
        foreach ($this->attainment->ploAttainment($programId, $cohortId)['plos'] as $r) {
            $current[$r['plo_id']] = $r['attainment_percent'];
        }

        return CqiAction::with('plo:id,code')
            ->where('program_id', $programId)
            ->where('cohort_id', $cohortId)
            // Cancelled actions sink to the bottom (kept for history).
            ->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")
            ->orderBy('status')
            ->latest()
            ->get()
            ->map(function (CqiAction $a) use ($current) {
                $before = $a->attainment_percent !== null ? (float) $a->attainment_percent : null;
                $now = $current[$a->plo_id] ?? null;
                return [
                    'uuid' => $a->uuid,
                    'plo_code' => $a->plo?->code,
                    'target_percent' => $a->target_percent !== null ? (float) $a->target_percent : null,
                    'before_percent' => $before,
                    'now_percent' => $now,
                    'delta' => ($before !== null && $now !== null) ? round($now - $before, 1) : null,
                    'finding' => $a->finding,
                    'root_cause' => $a->root_cause,
                    'action_plan' => $a->action_plan,
                    'owner' => $a->owner,
                    'status' => $a->status,
                ];
            })
            ->all();
    }
}
