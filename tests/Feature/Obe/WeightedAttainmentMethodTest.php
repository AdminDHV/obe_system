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
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\User;
use App\Services\OBE\Methods\WeightedAttainmentMethod;
use App\Services\OBE\ObeAttainmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Đo PI/PLO theo phương pháp WeightedAttainment (chuẩn khách) qua LUỒNG THẬT:
 * tín chỉ × mức I/R/M/A × điểm-PI-môn → PI_ratio; PI_ratio × trọng số PI → PLO_ratio (0..1).
 */
class WeightedAttainmentMethodTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;
    private Program $program;
    private Cohort $cohort;
    private User $lecturer;

    public function test_pi_plo_ratio_uses_credit_intensity_and_pi_weight(): void
    {
        $school = School::create(['code' => 'SCH-W', 'name' => 'Weighted']); // mặc định weighted_attainment
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $this->program = Program::create(['department_id' => $dept->id, 'code' => 'P1', 'name' => 'KTPM', 'school_id' => $school->id]);
        $this->cohort = Cohort::create(['program_id' => $this->program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $this->lecturer = User::factory()->create();

        $plo = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $pi1 = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1', 'description' => 'PI1', 'is_dummy' => false, 'weight' => 0.3]);
        $pi2 = Pi::create(['plo_id' => $plo->id, 'code' => 'PI2', 'description' => 'PI2', 'is_dummy' => false, 'weight' => 0.7]);

        $this->student = Student::create([
            'student_code' => 'SV1', 'full_name' => 'A', 'school_id' => $school->id,
            'cohort_id' => $this->cohort->id, 'program_id' => $this->program->id, 'status' => 'studying',
        ]);

        // Môn A (2 TC): CLO_A1→PI1 mức R(=2) điểm-môn 5.0 ; CLO_A2→PI2 mức I(=1) điểm-môn 8.0
        [$syllA, $classA] = $this->makeCourse($school, 'C-A', 2);
        $this->mapAndGrade($syllA, $classA, $pi1, 'R', 5.0, 5.0);
        $this->mapAndGrade($syllA, $classA, $pi2, 'I', 8.0, 8.0);

        // Môn B (3 TC): CLO_B1→PI1 mức M(=3) điểm-môn 10.0
        [$syllB, $classB] = $this->makeCourse($school, 'C-B', 3);
        $this->mapAndGrade($syllB, $classB, $pi1, 'M', 10.0, 10.0);

        app(WeightedAttainmentMethod::class)->computeForStudent($this->student, $this->program);

        // PI1 = (2·2·5 + 3·3·10) / (2·2·10 + 3·3·10) = 110/130 = 0.8462
        $r1 = StudentPiResult::where('student_id', $this->student->id)->where('pi_id', $pi1->id)->value('achieved_score');
        $this->assertEqualsWithDelta(0.8462, (float) $r1, 0.0005);

        // PI2 = (2·1·8) / (2·1·10) = 0.8
        $r2 = StudentPiResult::where('student_id', $this->student->id)->where('pi_id', $pi2->id)->value('achieved_score');
        $this->assertEqualsWithDelta(0.8, (float) $r2, 0.0005);

        // PLO1 = (0.8462·0.3 + 0.8·0.7) / 1.0 = 0.8138
        $ploScore = StudentPloResult::where('student_id', $this->student->id)->where('plo_id', $plo->id)->value('achieved_score');
        $this->assertEqualsWithDelta(0.8138, (float) $ploScore, 0.0005);

        // Attainment: thang tỉ lệ → ngưỡng 0.5; 1 SV (0.8138 ≥ 0.5) → 100%; is_ratio = true.
        $att = app(ObeAttainmentService::class)->ploAttainment($this->program->id, $this->cohort->id);
        $this->assertTrue($att['is_ratio']);
        $this->assertEqualsWithDelta(0.5, $att['threshold'], 0.0001);
        $this->assertSame(100.0, collect($att['plos'])->firstWhere('plo_code', 'PLO1')['attainment_percent']);
    }

    /** @return array{0: CourseSyllabus, 1: CourseClass} */
    private function makeCourse(School $school, string $code, int $credits): array
    {
        $course = Course::create(['code' => $code, 'name' => $code, 'credits' => $credits, 'school_id' => $school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $this->cohort->id, 'lecturer_id' => $this->lecturer->id,
            'class_code' => $code . '-CLS', 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
            'school_id' => $school->id,
        ]);

        return [$syllabus, $class];
    }

    /** Tạo 1 CLO map vào PI (mức cho trước) + bài process/final đo CLO đó + điểm SV → điểm-PI-môn. */
    private function mapAndGrade(CourseSyllabus $syllabus, CourseClass $class, Pi $pi, string $level, float $process, float $final): void
    {
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO-' . $pi->code . '-' . $syllabus->id, 'description' => 'x']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => $level]);

        foreach (['process' => $process, 'final' => $final] as $category => $score) {
            $a = Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $category . '-' . $clo->id,
                'assessment_category' => $category, 'weight_percent' => $category === 'final' ? 60 : 40, 'is_rubric_based' => false,
            ]);
            AssessmentCloMapping::create(['assessment_id' => $a->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);
            StudentGrade::create(['student_id' => $this->student->id, 'class_id' => $class->id, 'assessment_id' => $a->id, 'score' => $score]);
        }
    }
}
