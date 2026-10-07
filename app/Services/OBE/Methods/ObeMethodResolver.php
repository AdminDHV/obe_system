<?php

declare(strict_types=1);

namespace App\Services\OBE\Methods;

use App\Models\Program;

/**
 * Picks the PI/PLO measurement method from the program's school (`schools.obe_method`).
 * Defaults to 'weighted_attainment' when the program has no school.
 */
class ObeMethodResolver
{
    public function __construct(
        private StandardAverageMethod $standard,
        private WeightedAttainmentMethod $weighted,
    ) {}

    public function for(Program $program): ObeMeasurementMethod
    {
        $method = $program->school?->obe_method ?? 'weighted_attainment';

        return match ($method) {
            'standard_average' => $this->standard,
            default => $this->weighted,
        };
    }
}
