<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\StudentRosterImport;
use App\Models\Department;
use App\Models\FileBatch;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentRosterImportTest extends TestCase
{
    use RefreshDatabase;

    /** Dựng Ngành (điều kiện tối thiểu để xếp SV); trả school_id. */
    private function seedProgram(): int
    {
        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'IT', 'name' => 'CNTT']);
        Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'CNTT', 'name' => 'KTPM']);

        return $school->id;
    }

    private function batch(): FileBatch
    {
        return FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'student_roster', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
    }

    public function test_imports_students_and_creates_cohort(): void
    {
        $schoolId = $this->seedProgram();
        $batch = $this->batch();

        (new StudentRosterImport($batch, $schoolId))->collection(collect([
            ['mssv' => '20260001', 'ho_ten' => 'Nguyễn Văn A', 'ma_nganh' => 'CNTT', 'nam_khoa' => 2026],
            ['mssv' => '20260002', 'ho_ten' => 'Trần Thị B', 'ma_nganh' => 'CNTT', 'nam_khoa' => 2026],
        ]));

        $program = Program::where('code', 'CNTT')->first();
        $cohort = \App\Models\Cohort::where('name', 'Khóa 2026')->first();
        $this->assertNotNull($cohort);
        $this->assertDatabaseHas('students', [
            'school_id' => $schoolId, 'student_code' => '20260001', 'full_name' => 'Nguyễn Văn A',
            'program_id' => $program->id, 'cohort_id' => $cohort->id, 'status' => 'studying',
        ]);
        $this->assertSame(2, Student::count());
        $this->assertSame(2, $batch->fresh()->processed_rows);
    }

    public function test_reimport_updates_not_duplicates(): void
    {
        $schoolId = $this->seedProgram();

        (new StudentRosterImport($this->batch(), $schoolId))->collection(collect([
            ['mssv' => '20260001', 'ho_ten' => 'Tên Cũ', 'ma_nganh' => 'CNTT', 'nam_khoa' => 2026],
        ]));
        // Nhập lại cùng MSSV, đổi tên → cập nhật, không nhân đôi.
        (new StudentRosterImport($this->batch(), $schoolId))->collection(collect([
            ['mssv' => '20260001', 'ho_ten' => 'Tên Mới', 'ma_nganh' => 'CNTT', 'nam_khoa' => 2026],
        ]));

        $this->assertSame(1, Student::where('student_code', '20260001')->count());
        $this->assertSame('Tên Mới', Student::where('student_code', '20260001')->first()->full_name);
    }

    public function test_unknown_program_reports_row_error(): void
    {
        $schoolId = $this->seedProgram();
        $batch = $this->batch();

        (new StudentRosterImport($batch, $schoolId))->collection(collect([
            ['mssv' => '20260001', 'ho_ten' => 'A', 'ma_nganh' => 'KHONGCO', 'nam_khoa' => 2026],
        ]));

        $this->assertSame(0, Student::count());
        $this->assertSame(0, $batch->fresh()->processed_rows);
        $this->assertStringContainsString("KHONGCO", implode("\n", $batch->fresh()->error_log ?? []));
    }

    public function test_missing_required_fields_reports_error(): void
    {
        $schoolId = $this->seedProgram();
        $batch = $this->batch();

        (new StudentRosterImport($batch, $schoolId))->collection(collect([
            ['mssv' => '', 'ho_ten' => 'Thiếu MSSV', 'ma_nganh' => 'CNTT', 'nam_khoa' => 2026],
        ]));

        $this->assertSame(0, Student::count());
        $this->assertStringContainsString('thiếu', mb_strtolower(implode("\n", $batch->fresh()->error_log ?? [])));
    }
}
