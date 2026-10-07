<?php

declare(strict_types=1);

namespace App\Services\OBE;

/**
 * The shared process/final scoring rule used by BOTH CLO and PI computation
 * (docs/cach_tinh_CLO_PI_PLO.md):
 *   - only process columns  → simple average (NO coefficient)
 *   - only final columns    → simple average
 *   - both                  → (avg(process)·processWeight + avg(final)·finalWeight) / (sum of weights)
 *   - a single column       → that value (average of one element)
 * Coefficients come from ObeConfig (default 0.4 / 0.6).
 *
 * The weights are RENORMALISED by their own sum, so a school may enter the raw percentages of its
 * own grading scheme without having to pre-scale them. That matters when part of the course mark is
 * not measurable by outcome (attendance, participation): a school grading 10% attendance + 40%
 * midterm + 50% final enters 0.4 / 0.5, and measurement splits the two graded parts 4/9 : 5/9
 * instead of silently losing the missing tenth. With 0.4 / 0.6 the divisor is 1 and nothing changes.
 *
 * @param  array<int,float>  $process
 * @param  array<int,float>  $final
 */
class ObeScoreFormula
{
    public static function weightedFromCategories(array $process, array $final): ?float
    {
        $hasProcess = count($process) > 0;
        $hasFinal = count($final) > 0;

        if (!$hasProcess && !$hasFinal) {
            return null;
        }

        if ($hasProcess && !$hasFinal) {
            return array_sum($process) / count($process);
        }

        if (!$hasProcess && $hasFinal) {
            return array_sum($final) / count($final);
        }

        $avgProcess = array_sum($process) / count($process);
        $avgFinal = array_sum($final) / count($final);

        $processWeight = ObeConfig::processWeight();
        $finalWeight = ObeConfig::finalWeight();
        $total = $processWeight + $finalWeight;

        if ($total <= 0.0) {
            return ($avgProcess + $avgFinal) / 2;
        }

        return (($avgProcess * $processWeight) + ($avgFinal * $finalWeight)) / $total;
    }
}
