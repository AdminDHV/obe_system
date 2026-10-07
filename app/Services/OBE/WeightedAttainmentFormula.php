<?php

declare(strict_types=1);

namespace App\Services\OBE;

/**
 * Pure (DB-free) attainment formulas for the weighted method
 * (docs/cach_tinh_CLO_PI_PLO.md).
 * Output is a 0..1 ratio, unlike ObeScoreFormula which returns a 0-10 score.
 */
class WeightedAttainmentFormula
{
    /**
     * Attainment ratio of one PI, aggregated over the courses measuring it.
     *
     *   PI_ratio = Σ(credit × level × course_pi_score) / Σ(credit × level × 10)
     *
     * Raw credits are used: the 1/Σcredits factor cancels between numerator and denominator.
     *
     * @param  array<int,array{0:float|int,1:float|int,2:float|int}>  $contribs  each item = [credit, intensityWeight, score10]
     */
    public static function piRatio(array $contribs): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($contribs as [$credit, $weight, $score]) {
            $cw = (float) $credit * (float) $weight;
            $num += $cw * (float) $score;
            $den += $cw * 10.0;
        }

        return $den > 0.0 ? $num / $den : null;
    }

    /**
     * Attainment ratio of one PLO = weighted average of its PI ratios.
     *
     *   PLO = Σ(PI_ratio × pi_weight) / Σ(pi_weight)
     *
     * Weights default to 1 (plain average) and are self-normalising, so they need not sum to 1.
     *
     * @param  array<int,array{0:float|int,1:float|int|null}>  $piRatios  each item = [ratio, weight|null]
     */
    public static function ploRatio(array $piRatios): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($piRatios as [$ratio, $weight]) {
            $w = $weight === null ? 1.0 : (float) $weight;
            if ($w <= 0.0) {
                $w = 1.0;
            }
            $num += (float) $ratio * $w;
            $den += $w;
        }

        return $den > 0.0 ? $num / $den : null;
    }
}
