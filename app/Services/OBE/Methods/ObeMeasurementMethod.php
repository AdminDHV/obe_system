<?php

declare(strict_types=1);

namespace App\Services\OBE\Methods;

use App\Models\Program;
use App\Models\Student;

/**
 * Pluggable PI/PLO measurement method — each school picks one.
 * Implementations: StandardAverageMethod (0-10 score) and WeightedAttainmentMethod (0..1 ratio).
 */
interface ObeMeasurementMethod
{
    /** Compute and persist student_pi_results + student_plo_results for one student in a program. */
    public function computeForStudent(Student $student, Program $program): void;

    /** Pass threshold for a PLO's achieved_score, on this method's own scale. */
    public function ploPassThreshold(): float;

    /** True when achieved_score is a 0..1 ratio, false when it is a 0-10 score. */
    public function outputsRatio(): bool;
}
