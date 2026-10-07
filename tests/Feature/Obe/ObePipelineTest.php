<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\AssessmentCloMapping;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\Rubric;
use App\Models\RubricCriteria;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\User;
use App\Services\OBE\CloCalculationService;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\PloCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_a_pipeline_grades_to_clo_pi_plo_and_attainment(): void
    {
        $school = \App\Models\School::create(['code' => 'SCH-P', 'name' => 'Std', 'obe_method' => 'standard_average']);
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $course = Course::create(['code' => 'C1', 'name' => 'Toán', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'PI1.1', 'is_dummy' => false]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO1']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);

        // 1 process (8.0) + 1 final (10.0)  → công thức 0.4/0.6 = 9.2
        $aProcess = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Giữa kỳ', 'assessment_category' => 'process', 'weight_percent' => 40, 'is_rubric_based' => false]);
        $aFinal = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Cuối kỳ', 'assessment_category' => 'final', 'weight_percent' => 60, 'is_rubric_based' => false]);
        AssessmentCloMapping::create(['assessment_id' => $aProcess->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);
        AssessmentCloMapping::create(['assessment_id' => $aFinal->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);

        $lecturer = User::factory()->create();
        $class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => $lecturer->id,
            'class_code' => 'CLS1', 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
        ]);
        $student = Student::create([
            'student_code' => 'SV001', 'full_name' => 'Nguyễn A',
            'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
        ]);

        StudentGrade::create(['student_id' => $student->id, 'class_id' => $class->id, 'assessment_id' => $aProcess->id, 'score' => 8.0]);
        StudentGrade::create(['student_id' => $student->id, 'class_id' => $class->id, 'assessment_id' => $aFinal->id, 'score' => 10.0]);

        app(CloCalculationService::class)->calculateForStudent($student, $class);
        app(PloCalculationService::class)->calculateForStudent($student, $program);

        // PI computed Model A from raw grades = 8.0*0.4 + 10.0*0.6 = 9.2
        $piResult = StudentPiResult::where('student_id', $student->id)->where('pi_id', $pi->id)->first();
        $this->assertNotNull($piResult);
        $this->assertSame(9.2, round((float) $piResult->achieved_score, 2));

        $ploResult = StudentPloResult::where('student_id', $student->id)->where('plo_id', $plo->id)->first();
        $this->assertNotNull($ploResult);
        $this->assertSame(9.2, round((float) $ploResult->achieved_score, 2));
        $this->assertSame(4, (int) $ploResult->level); // 9.2 ≥ 8.5 → level 4

        // Attainment: 1 SV đạt (≥5.5) / 1 → 100%, vượt target 70%
        $att = app(ObeAttainmentService::class)->ploAttainment($program->id, $cohort->id);
        $row = collect($att['plos'])->firstWhere('plo_code', 'PLO1');
        $this->assertSame(100.0, $row['attainment_percent']);
        $this->assertTrue($row['met_target']);
    }

    /** Thành phần rubric-based KHÔNG có assessment_clo_mappings vẫn đo CLO nhờ tiêu chí rubric. */
    public function test_rubric_based_assessment_maps_clo_via_criteria(): void
    {
        $dept = Department::create(['code' => 'IT2', 'name' => 'CNTT2']);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P2', 'name' => 'KTPM2']);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026b', 'start_year' => 2026]);
        $course = Course::create(['code' => 'C2', 'name' => 'Web', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO1']);

        // Thành phần rubric-based, KHÔNG tạo AssessmentCloMapping.
        $asm = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Đồ án', 'assessment_category' => 'process', 'weight_percent' => 100, 'is_rubric_based' => true]);
        $rubric = Rubric::create(['assessment_id' => $asm->id, 'name' => 'Phiếu chấm đồ án']);
        RubricCriteria::create(['rubric_id' => $rubric->id, 'clo_id' => $clo->id, 'max_score' => 10, 'description' => 'Thuyết trình']);

        $lecturer = User::factory()->create();
        $class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => $lecturer->id,
            'class_code' => 'CLS2', 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
        ]);
        $student = Student::create(['student_code' => 'SV2', 'full_name' => 'B', 'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying']);
        StudentGrade::create(['student_id' => $student->id, 'class_id' => $class->id, 'assessment_id' => $asm->id, 'score' => 7.5]);

        // mappedCloIds() phải lấy CLO từ tiêu chí rubric (dù không có assessment_clo_mappings)
        $this->assertTrue($asm->fresh()->mappedCloIds()->contains($clo->id));

        app(CloCalculationService::class)->calculateForStudent($student, $class);

        $cloResult = StudentCloResult::where('student_id', $student->id)->where('clo_id', $clo->id)->first();
        $this->assertNotNull($cloResult, 'CLO phải được tính từ tiêu chí rubric');
        $this->assertSame(7.5, round((float) $cloResult->achieved_score, 2));
    }
}
