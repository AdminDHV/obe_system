<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Cohort;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backfill school_id cho bản ghi NULL: suy từ quan hệ + (1 trường → gán hết).
 */
class BackfillSchoolIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Migration tạo sẵn 1 trường DEFAULT — xoá để kiểm soát tập test (single-school shortcut).
        School::query()->forceDelete();
    }

    public function test_backfills_from_relations_and_single_school_shortcut(): void
    {
        $school = School::create(['code' => 'S', 'name' => 'Trường S', 'is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa D', 'school_id' => $school->id]);
        // Các bản ghi tạo tay bị NULL trường:
        $program = Program::create(['code' => 'P', 'name' => 'Ngành P', 'department_id' => $dept->id, 'school_id' => null]);
        $cohort = Cohort::create(['name' => 'K2026', 'start_year' => 2026, 'program_id' => $program->id, 'school_id' => null]);
        $student = Student::create(['student_code' => 'SV1', 'full_name' => 'SV 1', 'program_id' => $program->id, 'cohort_id' => $cohort->id, 'school_id' => null, 'status' => 'studying']);
        // Khoa mồ côi (không suy được) — sẽ nhờ single-school shortcut.
        $orphanDept = Department::create(['code' => 'D2', 'name' => 'Khoa D2', 'school_id' => null]);

        $this->artisan('obe:backfill-school')->assertOk();

        $this->assertSame($school->id, $program->fresh()->school_id, 'Program ← department');
        $this->assertSame($school->id, $cohort->fresh()->school_id, 'Cohort ← program');
        $this->assertSame($school->id, $student->fresh()->school_id, 'Student ← program');
        $this->assertSame($school->id, $orphanDept->fresh()->school_id, 'Single-school shortcut gán khoa mồ côi');
    }

    public function test_dry_run_does_not_write(): void
    {
        $school = School::create(['code' => 'S', 'name' => 'Trường S', 'is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa D', 'school_id' => $school->id]);
        $program = Program::create(['code' => 'P', 'name' => 'Ngành P', 'department_id' => $dept->id, 'school_id' => null]);

        $this->artisan('obe:backfill-school --dry-run')->assertOk();

        $this->assertNull($program->fresh()->school_id, 'Dry-run không được ghi');
    }
}
