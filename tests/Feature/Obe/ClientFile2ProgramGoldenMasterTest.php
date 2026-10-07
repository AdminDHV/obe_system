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
 * GOLDEN-MASTER cấp PI/PLO — bám ĐÚNG ví dụ trong file khách
 * `docs/New Đo lường chuẩn đầu ra chương trình đào tạo.xlsx`.
 *
 * Khác {@see WeightedAttainmentFormulaTest} (chỉ test hàm thuần nạp tay) và
 * {@see WeightedAttainmentMethodTest} (pipeline DB số liệu bịa): test này chạy XUYÊN DB
 * bằng CHÍNH ma trận môn×PI của file rồi khớp lại từng con số file in ra:
 *   PI1=0.6053, PI2=0.7778, PI3=0.7244 → PLO1=0.70466.
 *
 * Ma trận file (PLO1 đo qua 3 PI; trọng số PI 0.3/0.3/0.4):
 *   Môn1(2TC): PI1 R 7.5   , PI2 R 6.6667
 *   Môn2(2TC): PI2 I 7.5   , PI3 R 6.6667
 *   Môn3(3TC): PI1 R 6.6667, PI2 M 8.3333
 *   Môn4(3TC): PI1 M 5.0   , PI3 M 7.5
 */
class ClientFile2ProgramGoldenMasterTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Program $program;
    private Cohort $cohort;
    private Student $student;
    private User $lecturer;

    public function test_program_matrix_reproduces_client_pi_and_plo_ratios(): void
    {
        $this->school = School::create(['code' => 'SCH-GM2', 'name' => 'GoldenMaster2']); // mặc định weighted_attainment
        $dept = Department::create(['code' => 'D2', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $this->program = Program::create(['department_id' => $dept->id, 'code' => 'PG2', 'name' => 'CTĐT', 'school_id' => $this->school->id]);
        $this->cohort = Cohort::create(['program_id' => $this->program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $this->lecturer = User::factory()->create();

        $plo = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $pi1 = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1', 'description' => 'PI1', 'is_dummy' => false, 'weight' => 0.3]);
        $pi2 = Pi::create(['plo_id' => $plo->id, 'code' => 'PI2', 'description' => 'PI2', 'is_dummy' => false, 'weight' => 0.3]);
        $pi3 = Pi::create(['plo_id' => $plo->id, 'code' => 'PI3', 'description' => 'PI3', 'is_dummy' => false, 'weight' => 0.4]);

        $this->student = Student::create([
            'student_code' => 'SV1', 'full_name' => 'SV1', 'school_id' => $this->school->id,
            'cohort_id' => $this->cohort->id, 'program_id' => $this->program->id, 'status' => 'studying',
        ]);

        // Môn1 (2 TC)
        [$syll1, $class1] = $this->makeCourse('MON1', 2);
        $this->mapAndGrade($syll1, $class1, $pi1, 'R', 7.5);
        $this->mapAndGrade($syll1, $class1, $pi2, 'R', 20 / 3);   // 6.6667

        // Môn2 (2 TC)
        [$syll2, $class2] = $this->makeCourse('MON2', 2);
        $this->mapAndGrade($syll2, $class2, $pi2, 'I', 7.5);
        $this->mapAndGrade($syll2, $class2, $pi3, 'R', 20 / 3);   // 6.6667

        // Môn3 (3 TC)
        [$syll3, $class3] = $this->makeCourse('MON3', 3);
        $this->mapAndGrade($syll3, $class3, $pi1, 'R', 20 / 3);   // 6.6667
        $this->mapAndGrade($syll3, $class3, $pi2, 'M', 25 / 3);   // 8.3333

        // Môn4 (3 TC)
        [$syll4, $class4] = $this->makeCourse('MON4', 3);
        $this->mapAndGrade($syll4, $class4, $pi1, 'M', 5.0);
        $this->mapAndGrade($syll4, $class4, $pi3, 'M', 7.5);

        app(WeightedAttainmentMethod::class)->computeForStudent($this->student, $this->program);

        // PI ratios khớp file
        $this->assertEqualsWithDelta(0.6053, $this->piRatio($pi1), 0.0005, 'PI1 lệch file');
        $this->assertEqualsWithDelta(0.7778, $this->piRatio($pi2), 0.0005, 'PI2 lệch file');
        $this->assertEqualsWithDelta(0.7244, $this->piRatio($pi3), 0.0005, 'PI3 lệch file');

        // PLO1 = Σ(PI_ratio × trọng_số_PI) = 0.70466
        $plo1 = StudentPloResult::where('student_id', $this->student->id)->where('plo_id', $plo->id)->value('achieved_score');
        $this->assertEqualsWithDelta(0.70466, (float) $plo1, 0.0005, 'PLO1 lệch file');

        // Attainment tổng hợp: thang tỉ lệ, ngưỡng 0.5, 1 SV (0.70466 ≥ 0.5) → 100%
        $att = app(ObeAttainmentService::class)->ploAttainment($this->program->id, $this->cohort->id);
        $this->assertTrue($att['is_ratio']);
        $this->assertEqualsWithDelta(0.5, $att['threshold'], 0.0001);
        $this->assertSame(100.0, collect($att['plos'])->firstWhere('plo_code', 'PLO1')['attainment_percent']);
    }

    private function piRatio(Pi $pi): float
    {
        return (float) StudentPiResult::where('student_id', $this->student->id)->where('pi_id', $pi->id)->value('achieved_score');
    }

    /** @return array{0: CourseSyllabus, 1: CourseClass} */
    private function makeCourse(string $code, int $credits): array
    {
        $course = Course::create(['code' => $code, 'name' => $code, 'credits' => $credits, 'school_id' => $this->school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $this->cohort->id, 'lecturer_id' => $this->lecturer->id,
            'class_code' => $code . '-CLS', 'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
            'school_id' => $this->school->id,
        ]);

        return [$syllabus, $class];
    }

    /**
     * 1 CLO (thuộc môn) map vào PI ở mức I/R/M/A cho trước + 1 bài process + 1 bài final
     * cùng điểm = điểm-PI-môn (process 0.4 + final 0.6 = chính điểm đó).
     */
    private function mapAndGrade(CourseSyllabus $syllabus, CourseClass $class, Pi $pi, string $level, float $score): void
    {
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO-' . $pi->code . '-' . $syllabus->id, 'description' => 'x']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => $level]);

        foreach (['process', 'final'] as $category) {
            $a = Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $category . '-' . $clo->id,
                'assessment_category' => $category, 'weight_percent' => $category === 'final' ? 60 : 40, 'is_rubric_based' => false,
            ]);
            AssessmentCloMapping::create(['assessment_id' => $a->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);
            StudentGrade::create(['student_id' => $this->student->id, 'class_id' => $class->id, 'assessment_id' => $a->id, 'score' => $score]);
        }
    }
}
