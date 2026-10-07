<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\CourseClass;
use App\Models\FileBatch;
use App\Services\EnrollmentService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EnrollmentImport implements ToCollection, WithHeadingRow
{
    private FileBatch $batch;
    private CourseClass $courseClass;

    public function __construct(FileBatch $batch, CourseClass $courseClass)
    {
        $this->batch = $batch;
        $this->courseClass = $courseClass;
    }

    public function collection(Collection $rows): void
    {
        $service = app(EnrollmentService::class);
        $service->importFromExcel($rows, $this->courseClass, $this->batch);
    }
}
