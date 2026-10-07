<?php

declare(strict_types=1);

namespace App\Services\OBE\Strategies;

use App\Models\CourseClass;
use App\Models\Student;
use App\Services\OBE\CloCalculationService;

/**
 * Strategy 1 — legacy cohorts (≤2024): assign the final course grade to every CLO, no PI/PLO.
 */
class LegacyMeasurementStrategy implements CohortMeasurementStrategy
{
    public function __construct(
        private CloCalculationService $cloService,
    ) {}

    public function measureClo(Student $student, CourseClass $courseClass): void
    {
        $this->cloService->calculateLegacyForStudent($student, $courseClass);
    }

    /** Strategy 1 stops at CLO — there is no aggregation phase. */
    public function aggregateStudent(Student $student): void
    {
        // no-op
    }

    public function needsAggregation(): bool
    {
        return false;
    }
}
