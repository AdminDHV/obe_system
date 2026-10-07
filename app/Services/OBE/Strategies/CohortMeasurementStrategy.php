<?php

declare(strict_types=1);

namespace App\Services\OBE\Strategies;

use App\Models\CourseClass;
use App\Models\Student;

/**
 * Measurement strategy for a cohort; which one to use is decided by CohortMeasurementResolver.
 *
 * Measurement splits into two phases because their scopes differ:
 *   - CLO belongs to a (student, class)          → measureClo()
 *   - PI/PLO aggregate over all of a student's courses → aggregateStudent()
 */
interface CohortMeasurementStrategy
{
    /** Phase 1 — measure CLOs for one (student, class). */
    public function measureClo(Student $student, CourseClass $courseClass): void;

    /** Phase 2 — aggregate PI/PLO for one student, run once after every class CLO is done. */
    public function aggregateStudent(Student $student): void;

    /** Whether this strategy computes PI/PLO at all (strategy 1 / cohort ≤2024 does not). */
    public function needsAggregation(): bool;
}
