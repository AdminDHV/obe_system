<?php

declare(strict_types=1);

namespace App\Services\OBE\Methods;

use App\Models\Program;
use App\Models\Student;
use App\Services\OBE\ObeConfig;
use App\Services\OBE\PloCalculationService;

/**
 * PI = 0.4×process + 0.6×final on raw scores, PLO = plain average of its PIs.
 * Output is a 0-10 score; wraps PloCalculationService.
 */
class StandardAverageMethod implements ObeMeasurementMethod
{
    public function __construct(private PloCalculationService $ploService) {}

    public function computeForStudent(Student $student, Program $program): void
    {
        $this->ploService->calculateForStudent($student, $program);
    }

    public function ploPassThreshold(): float
    {
        return ObeConfig::ploPassThreshold();
    }

    public function outputsRatio(): bool
    {
        return false;
    }
}
