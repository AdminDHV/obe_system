<?php

declare(strict_types=1);

namespace App\Services\OBE\Strategies;

use App\Models\CourseClass;
use App\Models\Student;
use App\Services\OBE\CloCalculationService;
use App\Services\OBE\Methods\ObeMethodResolver;

/**
 * Strategy 2 (2025) and 3 (≥2026), shared: CLO → PI → PLO. A PLO not split into PIs is stored
 * with one internal PI and computed the same way. PI/PLO is delegated to the school's method via ObeMethodResolver.
 */
class StandardMeasurementStrategy implements CohortMeasurementStrategy
{
    public function __construct(
        private CloCalculationService $cloService,
        private ObeMethodResolver $methods,
    ) {}

    public function measureClo(Student $student, CourseClass $courseClass): void
    {
        $this->cloService->calculateForStudent($student, $courseClass);
    }

    public function aggregateStudent(Student $student): void
    {
        $program = $student->program;
        if ($program) {
            $this->methods->for($program)->computeForStudent($student, $program);
        }
    }

    public function needsAggregation(): bool
    {
        return true;
    }
}
