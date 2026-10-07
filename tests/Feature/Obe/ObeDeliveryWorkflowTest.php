<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Imports\BulkGradeImport;
use App\Jobs\Calculations\GenerateReportJob;
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
use App\Models\ReportSnapshot;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\User;
use App\Services\OBE\ObeAttainmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ObeDeliveryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** Dựng 1 lớp đầy đủ (assessment process+final map vào 1 CLO→PI→PLO). */
    private function makeClass(Program $program, Cohort $cohort, string $classCode, ?int $schoolId = null): CourseClass
    {
        $course = Course::create(['code' => 'C-' . $classCode, 'name' => 'Môn ' . $classCode, 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO1']);
        $plo = Plo::firstOrCreate(['program_id' => $program->id, 'code' => 'PLO1'], ['description' => 'PLO1']);
        $pi = Pi::firstOrCreate(['plo_id' => $plo->id, 'code' => 'PI1.1'], ['description' => 'PI1.1', 'is_dummy' => false]);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);

        $aProcess = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Giữa kỳ', 'assessment_category' => 'process', 'weight_percent' => 40, 'is_rubric_based' => false]);
        $aFinal = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Cuối kỳ', 'assessment_category' => 'final', 'weight_percent' => 60, 'is_rubric_based' => false]);
        AssessmentCloMapping::create(['assessment_id' => $aProcess->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);
        AssessmentCloMapping::create(['assessment_id' => $aFinal->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);

        return CourseClass::create([
            'school_id' => $schoolId,
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => User::factory()->create()->id,
            'class_code' => $classCode, 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
        ]);
    }

    public function test_bulk_import_routes_rows_to_correct_class(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);

        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'IT', 'name' => 'CNTT']);
        $program = Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM']);
        $cohort = Cohort::create(['school_id' => $school->id, 'program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);

        $classA = $this->makeClass($program, $cohort, 'CLS-A', $school->id);
        $classB = $this->makeClass($program, $cohort, 'CLS-B', $school->id);

        $sv = Student::create(['school_id' => $school->id, 'student_code' => 'SV001', 'full_name' => 'A', 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);

        $rows = collect([
            ['ma_lop' => 'CLS-A', 'mssv' => 'SV001', 'ten_bai_danh_gia' => 'Giữa kỳ', 'diem' => 8.0],
            ['ma_lop' => 'CLS-B', 'mssv' => 'SV001', 'ten_bai_danh_gia' => 'Cuối kỳ', 'diem' => 9.0],
            ['ma_lop' => 'KHONG-TON-TAI', 'mssv' => 'SV001', 'ten_bai_danh_gia' => 'Giữa kỳ', 'diem' => 5.0],
        ]);

        (new BulkGradeImport($batch, $school->id))->collection($rows);

        // Dòng 1 → lớp A, dòng 2 → lớp B (định tuyến đúng theo Mã lớp)
        $this->assertDatabaseHas('student_grades', ['student_id' => $sv->id, 'class_id' => $classA->id, 'score' => 8.0]);
        $this->assertDatabaseHas('student_grades', ['student_id' => $sv->id, 'class_id' => $classB->id, 'score' => 9.0]);
        $this->assertSame(2, $batch->fresh()->processed_rows);
        // Dòng mã lớp sai → vào error_log
        $this->assertNotEmpty($batch->fresh()->error_log);
    }

    public function test_bulk_import_sets_legacy_flag_for_pre_2024_cohort(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]);

        $school = School::create(['code' => 'SCH', 'name' => 'Trường Test']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'IT3', 'name' => 'CNTT3']);
        $program = Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P3', 'name' => 'KTPM3']);
        $legacyCohort = Cohort::create(['school_id' => $school->id, 'program_id' => $program->id, 'name' => 'K2023', 'start_year' => 2023]);
        $class = $this->makeClass($program, $legacyCohort, 'CLS-OLD', $school->id);
        Student::create(['school_id' => $school->id, 'student_code' => 'SV9', 'full_name' => 'C', 'cohort_id' => $legacyCohort->id, 'program_id' => $program->id, 'status' => 'studying']);

        $batch = FileBatch::create(['user_id' => User::factory()->create()->id, 'file_path' => 'x', 'type' => 'grade_bulk', 'status' => 'processing', 'processed_rows' => 0, 'total_rows' => 0]);
        (new BulkGradeImport($batch, $school->id))->collection(collect([
            ['ma_lop' => 'CLS-OLD', 'mssv' => 'SV9', 'ten_bai_danh_gia' => 'Giữa kỳ', 'diem' => 6.0],
        ]));

        $this->assertDatabaseHas('student_grades', ['class_id' => $class->id, 'is_legacy_data' => true]);
    }

    public function test_recalculate_command_rebuilds_plo_results(): void
    {
        Event::fake([\App\Events\GradeUpdatedEvent::class]); // chặn recalc tự động lúc tạo grade

        // Pin phương pháp StandardAverage (điểm thang 10) để test luồng cũ ổn định.
        $school = \App\Models\School::create(['code' => 'SCH-D', 'name' => 'Std', 'obe_method' => 'standard_average']);
        $dept = Department::create(['code' => 'IT4', 'name' => 'CNTT4', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P4', 'name' => 'KTPM4', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026d', 'start_year' => 2026]);
        $class = $this->makeClass($program, $cohort, 'CLS-D');
        $sv = Student::create(['student_code' => 'SV-D', 'full_name' => 'D', 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);

        $assessments = Assessment::where('syllabus_id', $class->syllabus_id)->get();
        StudentGrade::create(['student_id' => $sv->id, 'class_id' => $class->id, 'assessment_id' => $assessments->firstWhere('assessment_category', 'process')->id, 'score' => 8.0]);
        StudentGrade::create(['student_id' => $sv->id, 'class_id' => $class->id, 'assessment_id' => $assessments->firstWhere('assessment_category', 'final')->id, 'score' => 10.0]);

        // Chưa có PLO result trước khi chạy
        $this->assertDatabaseMissing('student_plo_results', ['student_id' => $sv->id]);

        Artisan::call('obe:recalculate', ['--cohort' => $cohort->id]);

        // Sau khi chạy lệnh: PI/PLO được tính (9.2 = 8*0.4 + 10*0.6)
        $plo = Plo::where('program_id', $program->id)->where('code', 'PLO1')->first();
        $result = StudentPloResult::where('student_id', $sv->id)->where('plo_id', $plo->id)->first();
        $this->assertNotNull($result);
        $this->assertSame(9.2, round((float) $result->achieved_score, 2));

        // Pha 3: lệnh ghi 1 nhật ký đo lường (MeasurementRun) đúng phạm vi.
        $this->assertDatabaseHas('measurement_runs', ['source' => 'command', 'cohort_id' => $cohort->id, 'pairs_count' => 1]);
    }

    public function test_generate_report_includes_attainment_block(): void
    {
        Storage::fake('local');

        $dept = Department::create(['code' => 'IT5', 'name' => 'CNTT5']);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P5', 'name' => 'KTPM5']);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026e', 'start_year' => 2026]);
        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'Mô tả PLO1']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'Mô tả PI1.1', 'is_dummy' => false, 'weight' => 1.0]);
        $sv = Student::create(['student_code' => 'SV-E', 'full_name' => 'E', 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);
        StudentPloResult::create(['student_id' => $sv->id, 'plo_id' => $plo->id, 'achieved_score' => 9.2, 'level' => 4]);
        StudentPiResult::create(['student_id' => $sv->id, 'pi_id' => $pi->id, 'achieved_score' => 9.0]);

        $snapshot = ReportSnapshot::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Test report',
            'type' => 'aun_qa',
            'filters' => ['program_id' => $program->id, 'cohort_id' => $cohort->id],
            'status' => 'queued',
        ]);

        (new GenerateReportJob($snapshot))->handle(
            app(ObeAttainmentService::class),
            app(\App\Services\OBE\StudentProgressService::class),
        );

        $snapshot->refresh();
        $this->assertSame('generated', $snapshot->status);
        $csv = Storage::get($snapshot->file_path);

        // Có khối attainment + ma trận
        $this->assertStringContainsString('PLO ATTAINMENT', $csv);
        $this->assertStringContainsString('MA TRẬN SINH VIÊN', $csv);
        // 1 SV đạt (9.2 ≥ 5.5) / 1 → 100% → "Đạt"
        $this->assertStringContainsString('100', $csv);
        $this->assertStringContainsString('Đạt', $csv);
        // Ma trận có MSSV
        $this->assertStringContainsString('SV-E', $csv);

        // CSV phải có CẢ PI — trước đây chỉ có PLO nên operator tải về gửi trường là thiếu hẳn
        // phần hội đồng kiểm định soi kỹ nhất (đồng bộ với bản Excel).
        $this->assertStringContainsString('PI ATTAINMENT', $csv);
        $this->assertStringContainsString('MA TRẬN SINH VIÊN × PI', $csv);
        $this->assertStringContainsString('PI1.1', $csv);
    }
}
