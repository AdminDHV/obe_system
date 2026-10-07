<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\BulkGradeImport;
use App\Models\Assessment;
use App\Models\AssessmentCloMapping;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\FileBatch;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\OBE\ObeReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ObeReadinessAndLegacyTest extends TestCase
{
    use RefreshDatabase;

    private function program(string $code): Program
    {
        $dept = Department::create(['code' => 'D-' . $code, 'name' => 'Khoa ' . $code]);

        return Program::create(['department_id' => $dept->id, 'code' => $code, 'name' => 'Ngành ' . $code]);
    }

    private function syllabusWithClo(string $code): CourseSyllabus
    {
        $course = Course::create(['code' => 'C-' . $code, 'name' => 'Môn ' . $code, 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO1']);

        return $syllabus;
    }

    private function makeClass(Cohort $cohort, CourseSyllabus $syllabus, string $classCode, ?int $schoolId = null): CourseClass
    {
        return CourseClass::create([
            'school_id' => $schoolId,
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => User::factory()->create()->id,
            'class_code' => $classCode, 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
        ]);
    }

    /** @return array<string,mixed> */
    private function findCheck(array $result, string $key): array
    {
        foreach ($result['checks'] as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        $this->fail("Check '{$key}' not found in readiness result.");
    }

    // ── PA3 ───────────────────────────────────────────────────────────────

    public function test_readiness_pa3_full_structure_is_ready(): void
    {
        $program = $this->program('P3');
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $syllabus = $this->syllabusWithClo('S3');
        $this->makeClass($cohort, $syllabus, 'CLS-3');

        $clo = Clo::where('syllabus_id', $syllabus->id)->first();
        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'PI1.1', 'is_dummy' => false]);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);
        $a = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Cuối kỳ', 'assessment_category' => 'final', 'weight_percent' => 100, 'is_rubric_based' => false]);
        AssessmentCloMapping::create(['assessment_id' => $a->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);

        $result = app(ObeReadinessService::class)->check($program->id, $cohort->id);

        $this->assertSame(3, $result['strategy']);
        $this->assertTrue($result['ready'], 'PA3 đầy đủ phải sẵn sàng');
    }

    public function test_readiness_pa3_missing_pi_is_not_ready(): void
    {
        $program = $this->program('P3b');
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2027', 'start_year' => 2027]);
        $syllabus = $this->syllabusWithClo('S3b');
        $this->makeClass($cohort, $syllabus, 'CLS-3b');
        // PLO nhưng KHÔNG có PI
        Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'PLO1']);

        $result = app(ObeReadinessService::class)->check($program->id, $cohort->id);

        $this->assertSame(3, $result['strategy']);
        $this->assertFalse($result['ready']);
        $this->assertFalse($this->findCheck($result, 'pis')['ok']);
    }

    // ── PA2 ───────────────────────────────────────────────────────────────

    public function test_readiness_pa2_with_dummy_pi_is_ready(): void
    {
        $program = $this->program('P2');
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2025', 'start_year' => 2025]);
        $syllabus = $this->syllabusWithClo('S2');
        $this->makeClass($cohort, $syllabus, 'CLS-2');

        $clo = Clo::where('syllabus_id', $syllabus->id)->first();
        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $dummy = Pi::create(['plo_id' => $plo->id, 'code' => 'PI-Dummy-PLO1', 'description' => 'PI ảo', 'is_dummy' => true]);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $dummy->id, 'mapping_level' => 'M']);
        $a = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Cuối kỳ', 'assessment_category' => 'final', 'weight_percent' => 100, 'is_rubric_based' => false]);
        AssessmentCloMapping::create(['assessment_id' => $a->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);

        $result = app(ObeReadinessService::class)->check($program->id, $cohort->id);

        $this->assertSame(2, $result['strategy']);
        $this->assertTrue($result['ready'], 'PA2 với PI ảo phải sẵn sàng');
    }

    // ── PA1 (legacy) ──────────────────────────────────────────────────────

    public function test_readiness_pa1_only_needs_clo_and_carrier_assessment(): void
    {
        $program = $this->program('P1');
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2023', 'start_year' => 2023]);
        $syllabus = $this->syllabusWithClo('S1');
        $this->makeClass($cohort, $syllabus, 'CLS-1');
        Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Điểm tổng kết', 'assessment_category' => 'final', 'weight_percent' => 100, 'is_rubric_based' => false]);

        $result = app(ObeReadinessService::class)->check($program->id, $cohort->id);

        $this->assertSame(1, $result['strategy']);
        $this->assertTrue($result['ready'], 'PA1 chỉ cần CLO + assessment carrier');
        // PA1 không kiểm tra PLO/PI
        $keys = array_column($result['checks'], 'key');
        $this->assertNotContains('plos', $keys);
        $this->assertNotContains('pis', $keys);
        $this->assertContains('legacy_carrier', $keys);
    }

    public function test_readiness_pa1_missing_carrier_assessment_is_not_ready(): void
    {
        $program = $this->program('P1b');
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2022', 'start_year' => 2022]);
        $syllabus = $this->syllabusWithClo('S1b'); // có CLO nhưng KHÔNG có assessment
        $this->makeClass($cohort, $syllabus, 'CLS-1b');

        $result = app(ObeReadinessService::class)->check($program->id, $cohort->id);

        $this->assertSame(1, $result['strategy']);
        $this->assertFalse($result['ready']);
        $this->assertFalse($this->findCheck($result, 'legacy_carrier')['ok']);
    }

    // ── Legacy bulk import fallback ───────────────────────────────────────

    public function test_legacy_import_falls_back_to_first_assessment_as_carrier(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);

        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $program = $this->program('PL');
        $cohort = Cohort::create(['school_id' => $school->id, 'program_id' => $program->id, 'name' => 'K2023L', 'start_year' => 2023]);
        $syllabus = $this->syllabusWithClo('SL');
        $class = $this->makeClass($cohort, $syllabus, 'CLS-L', $school->id);
        // Assessment có tên KHÁC "Điểm tổng kết"
        Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Bài thi', 'assessment_category' => 'final', 'weight_percent' => 100, 'is_rubric_based' => false]);
        $sv = Student::create(['school_id' => $school->id, 'student_code' => 'SVL', 'full_name' => 'L', 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        (new BulkGradeImport($batch, $school->id))->collection(collect([
            ['ma_lop' => 'CLS-L', 'mssv' => 'SVL', 'ten_bai_danh_gia' => 'Điểm tổng kết', 'diem' => 7.5],
        ]));

        // Dù tên không khớp, khóa cũ vẫn nhận điểm (dùng assessment đầu tiên làm carrier)
        $this->assertDatabaseHas('student_grades', ['student_id' => $sv->id, 'class_id' => $class->id, 'score' => 7.5, 'is_legacy_data' => true]);
        $this->assertSame(1, $batch->fresh()->processed_rows);
    }

    public function test_legacy_import_accepts_three_column_file_without_assessment_name(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);

        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $program = $this->program('PL3');
        $cohort = Cohort::create(['school_id' => $school->id, 'program_id' => $program->id, 'name' => 'K2023L3', 'start_year' => 2023]);
        $syllabus = $this->syllabusWithClo('SL3');
        $class = $this->makeClass($cohort, $syllabus, 'CLS-L3', $school->id);
        Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Điểm tổng kết', 'assessment_category' => 'final', 'weight_percent' => 100, 'is_rubric_based' => false]);
        $sv = Student::create(['school_id' => $school->id, 'student_code' => 'SVL3', 'full_name' => 'L3', 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        // File 3 cột: KHÔNG có 'ten_bai_danh_gia'
        (new BulkGradeImport($batch, $school->id))->collection(collect([
            ['ma_lop' => 'CLS-L3', 'mssv' => 'SVL3', 'diem' => 7.0],
        ]));

        $this->assertDatabaseHas('student_grades', ['student_id' => $sv->id, 'class_id' => $class->id, 'score' => 7.0, 'is_legacy_data' => true]);
        $this->assertSame(1, $batch->fresh()->processed_rows);
    }

    public function test_standard_import_requires_assessment_name(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);

        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $program = $this->program('PS');
        $cohort = Cohort::create(['school_id' => $school->id, 'program_id' => $program->id, 'name' => 'K2026S', 'start_year' => 2026]);
        $syllabus = $this->syllabusWithClo('SS');
        $this->makeClass($cohort, $syllabus, 'CLS-S', $school->id);
        Student::create(['school_id' => $school->id, 'student_code' => 'SVS', 'full_name' => 'S', 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        // Khóa 2026 mà thiếu cột 'ten_bai_danh_gia' → phải báo lỗi
        (new BulkGradeImport($batch, $school->id))->collection(collect([
            ['ma_lop' => 'CLS-S', 'mssv' => 'SVS', 'diem' => 7.0],
        ]));

        $this->assertSame(0, $batch->fresh()->processed_rows);
        $errors = implode("\n", $batch->fresh()->error_log ?? []);
        $this->assertStringContainsString('Tên bài đánh giá', $errors);
    }

    public function test_legacy_import_without_any_assessment_reports_clear_error(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);

        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $program = $this->program('PL2');
        $cohort = Cohort::create(['school_id' => $school->id, 'program_id' => $program->id, 'name' => 'K2023L2', 'start_year' => 2023]);
        $syllabus = $this->syllabusWithClo('SL2'); // KHÔNG có assessment nào
        $this->makeClass($cohort, $syllabus, 'CLS-L2', $school->id);
        Student::create(['school_id' => $school->id, 'student_code' => 'SVL2', 'full_name' => 'L2', 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        (new BulkGradeImport($batch, $school->id))->collection(collect([
            ['ma_lop' => 'CLS-L2', 'mssv' => 'SVL2', 'ten_bai_danh_gia' => 'Điểm tổng kết', 'diem' => 7.5],
        ]));

        $this->assertSame(0, $batch->fresh()->processed_rows);
        $errors = implode("\n", $batch->fresh()->error_log ?? []);
        $this->assertStringContainsString('khóa cũ', $errors);
    }
}
