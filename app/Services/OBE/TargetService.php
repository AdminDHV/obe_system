<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\ObeTarget;
use App\Models\Plo;
use App\Models\Program;

/**
 * Declares / updates the per-PLO target attainment (%) of a program.
 * A null or empty target_percent deletes the row, so the PLO falls back to the global target.
 */
class TargetService
{
    /**
     * @param  array<int, array{plo_id: int, target_percent: float|int|null}>  $targets
     */
    public function upsertForProgram(int $programId, array $targets): void
    {
        $program = Program::findOrFail($programId);
        $schoolId = $program->school_id;

        // Only accept PLOs of this program — blocks cross-tenant writes.
        $validPloIds = Plo::where('program_id', $programId)->pluck('id')->all();

        foreach ($targets as $t) {
            $ploId = (int) ($t['plo_id'] ?? 0);
            if (!in_array($ploId, $validPloIds, true)) {
                continue;
            }

            $raw = $t['target_percent'] ?? null;
            $key = ['school_id' => $schoolId, 'target_type' => 'plo', 'plo_id' => $ploId, 'pi_id' => null];

            if ($raw === null || $raw === '') {
                ObeTarget::where($key)->delete();
                continue;
            }

            ObeTarget::updateOrCreate($key, ['target_percent' => (float) $raw]);
        }
    }

    /**
     * PLOs of a program with their explicit target (if any) and the effective target
     * after fallback, for the target-editing screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForProgram(int $programId): array
    {
        $program = Program::find($programId);
        $schoolId = $program?->school_id;

        $explicit = ObeTarget::where('target_type', 'plo')
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->pluck('target_percent', 'plo_id');

        return Plo::where('program_id', $programId)->orderBy('code')->get()
            ->map(fn (Plo $plo) => [
                'plo_id' => $plo->id,
                'code' => $plo->code,
                'description' => $plo->description,
                'target_percent' => isset($explicit[$plo->id]) ? (float) $explicit[$plo->id] : null,
                'effective_target' => ObeConfig::targetForPlo($plo->id, $schoolId),
            ])
            ->all();
    }
}
