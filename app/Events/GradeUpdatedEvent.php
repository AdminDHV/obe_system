<?php

namespace App\Events;

use App\Models\CourseClass;
use App\Models\Student;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GradeUpdatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Student $student,
        public CourseClass $courseClass,
    ) {}
}
