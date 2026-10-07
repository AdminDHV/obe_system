<?php

declare(strict_types=1);

namespace App\Services\OBE\Strategies;

use App\Enums\CohortStrategy;
use App\Models\CourseClass;

/**
 * Picks the measurement strategy from the cohort (cohort.start_year → CohortStrategy).
 * This is the only place that decides legacy vs standard for the recalculation flow.
 */
class CohortMeasurementResolver
{
    public function __construct(
        private LegacyMeasurementStrategy $legacy,
        private StandardMeasurementStrategy $standard,
    ) {}

    public function for(CourseClass $courseClass): CohortMeasurementStrategy
    {
        $cohort = $courseClass->cohort;
        // A class with no cohort is treated as standard.
        if (! $cohort) {
            return $this->standard;
        }

        // An explicit grade granularity ('total' or 'question') always measures up to PI/PLO,
        // even for ≤2024 cohorts that would otherwise default to legacy CLO-only.
        if (in_array($cohort->grade_granularity ?? null, ['total', 'question'], true)) {
            return $this->standard;
        }

        return CohortStrategy::fromStartYear((int) $cohort->start_year)->isLegacy()
            ? $this->legacy
            : $this->standard;
    }
}
