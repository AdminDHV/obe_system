<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\LegacyGradeImport;
use App\Models\Clo;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\FileBatch;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\User;
use App\Services\OBE\ObeRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LegacyGradeImportTest extends TestCase
{
    use RefreshDatabase;

    /** Chỉ dựng Ngành + Môn + CLO (điều kiện tối thiểu); KHÔNG tạo khóa/lớp/SV. Trả school_id. */
    private function seedProgramCourseClo(): int
    {
        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'IT', 'name' => 'CNTT']);
        Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'CNTT', 'name' => 'KTPM']);
        $course = Course::create(['school_id' => $school->id, 'code' => 'IT101', 'name' => 'Nhập môn', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'A']);
        Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO2', 'description' => 'B']);

        return $school->id;
    }

    public function test_legacy_import_auto_provisions_everything_from_file(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);
        $schoolId = $this->seedProgramCourseClo();

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        (new LegacyGradeImport($batch, $schoolId))->collection(collect([
            ['ma_nganh' => 'CNTT', 'nam_khoa' => 2022, 'ma_mon' => 'IT101', 'mssv' => 'SV900', 'diem' => 7, 'ten_sv' => 'Cũ A'],
        ]));

        // Tự sinh khóa / lớp / SV / bài carrier — operator KHÔNG phải tạo tay.
        $this->assertDatabaseHas('cohorts', ['name' => 'Khóa 2022', 'start_year' => 2022]);
        $this->assertDatabaseHas('course_classes', ['class_code' => 'IT101-2022-LEGACY']);
        $this->assertDatabaseHas('students', ['student_code' => 'SV900', 'full_name' => 'Cũ A']);
        $this->assertDatabaseHas('assessments', ['name' => 'Điểm tổng kết']);
        $this->assertDatabaseHas('student_grades', ['score' => 7, 'is_legacy_data' => true]);
        $this->assertSame(1, $batch->fresh()->processed_rows);
    }

    public function test_legacy_measure_assigns_total_to_all_clos(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);
        $schoolId = $this->seedProgramCourseClo();

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        (new LegacyGradeImport($batch, $schoolId))->collection(collect([
            ['ma_nganh' => 'CNTT', 'nam_khoa' => 2022, 'ma_mon' => 'IT101', 'mssv' => 'SV900', 'diem' => 7],
        ]));

        $student = Student::where('student_code', 'SV900')->first();
        $class = CourseClass::where('class_code', 'IT101-2022-LEGACY')->first();
        app(ObeRecalculationService::class)->recalculateStudentInClass($student, $class);

        // Điểm tổng (7) gán cho CẢ 2 CLO; không có PI/PLO.
        $this->assertSame(2, StudentCloResult::where('student_id', $student->id)->count());
        $this->assertEquals(7.0, (float) StudentCloResult::where('student_id', $student->id)->first()->achieved_score);
        $this->assertSame(0, $student->piResults()->count());
        $this->assertSame(0, $student->ploResults()->count());
    }

    public function test_legacy_import_errors_when_course_has_no_clo(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);
        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'IT', 'name' => 'CNTT']);
        Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'CNTT', 'name' => 'KTPM']);
        $course = Course::create(['school_id' => $school->id, 'code' => 'IT102', 'name' => 'Môn không CLO', 'credits' => 3]);
        CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        (new LegacyGradeImport($batch, $school->id))->collection(collect([
            ['ma_nganh' => 'CNTT', 'nam_khoa' => 2022, 'ma_mon' => 'IT102', 'mssv' => 'SV901', 'diem' => 7],
        ]));

        $this->assertSame(0, $batch->fresh()->processed_rows);
        $this->assertStringContainsString('chưa có CLO', implode("\n", $batch->fresh()->error_log ?? []));
    }
}
