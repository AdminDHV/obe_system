<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use App\Services\OBE\Strategies\CohortMeasurementResolver;
use App\Services\OBE\Strategies\LegacyMeasurementStrategy;
use App\Services\OBE\Strategies\StandardMeasurementStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CohortMeasurementResolverTest extends TestCase
{
    use RefreshDatabase;

    private function classForYear(int $year): CourseClass
    {
        $dept = Department::create(['code' => 'D' . $year, 'name' => 'Khoa ' . $year]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P' . $year, 'name' => 'Ngành ' . $year]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K' . $year, 'start_year' => $year]);
        $course = Course::create(['code' => 'C' . $year, 'name' => 'Môn ' . $year, 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);

        return CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => User::factory()->create()->id,
            'class_code' => 'CLS' . $year, 'academic_year' => $year . '-' . ($year + 1), 'semester' => 1, 'max_capacity' => 40,
        ]);
    }

    public function test_resolves_legacy_for_pre_2025(): void
    {
        $resolver = app(CohortMeasurementResolver::class);
        $this->assertInstanceOf(LegacyMeasurementStrategy::class, $resolver->for($this->classForYear(2023)));
        $this->assertInstanceOf(LegacyMeasurementStrategy::class, $resolver->for($this->classForYear(2024)));
    }

    public function test_resolves_standard_for_2025_and_2026(): void
    {
        $resolver = app(CohortMeasurementResolver::class);
        $this->assertInstanceOf(StandardMeasurementStrategy::class, $resolver->for($this->classForYear(2025)));
        $this->assertInstanceOf(StandardMeasurementStrategy::class, $resolver->for($this->classForYear(2026)));
    }
}
