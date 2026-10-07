<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Assessment;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentPloResult;
use App\Models\User;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\StudentProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TIẾN ĐỘ TÍCH LŨY & ĐIỀU KIỆN GỘP THỐNG KÊ.
 *
 * Ca thật từ trường (khóa QTKD 2024): học viên 2412HV9036 mới có điểm 11/16 học phần
 * (33/51 TC, chương trình yêu cầu 60 TC) nhưng hệ thống vẫn xuất ĐỦ 23 PI + 8 PLO y hệt
 * học viên học đủ, rồi đếm luôn vào dòng "33 SV đo · 33 đạt · 100%". Trường phản hồi
 * "kết quả chưa đúng" chính vì chỗ này — phép đo không sai, nhưng MẪU ĐO bị trộn.
 *
 * Test khoá 3 điều:
 *   1. Đếm đúng tiến độ tích lũy (chỉ môn ĐẠT mới cho tín chỉ).
 *   2. SV chưa đủ tích lũy bị loại khỏi thống kê mức đạt của khóa.
 *   3. Ngành CHƯA khai `required_credits` ⇒ không lọc ai (giữ nguyên hành vi cũ).
 */
class StudentProgressEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED_CREDITS = 60;

    /** [mã môn, tín chỉ] — 20 môn × 3 TC = 60 TC nếu học đủ. */
    private const COURSES = 20;

    private Program $program;
    private Cohort $cohort;
    private Plo $plo;

    /** @var array<string, CourseClass> mã môn → lớp */
    private array $classes = [];

    private function seedProgram(?int $requiredCredits = self::REQUIRED_CREDITS): void
    {
        $school = School::create(['code' => 'SDHP', 'name' => 'SĐH Test tiến độ']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'SDH', 'name' => 'Sau đại học']);
        $this->program = Program::create([
            'school_id' => $school->id, 'department_id' => $dept->id,
            'code' => 'QTKD', 'name' => 'QTKD Thạc sĩ', 'required_credits' => $requiredCredits,
        ]);
        $this->plo = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'PLO1']);
        $this->cohort = Cohort::create([
            'school_id' => $school->id, 'program_id' => $this->program->id,
            'name' => '2024', 'start_year' => 2024, 'grade_granularity' => 'question',
        ]);

        $lecturerId = User::factory()->create()->id;

        for ($i = 1; $i <= self::COURSES; $i++) {
            $code = 'BUS' . (500 + $i);
            $course = Course::create([
                'school_id' => $school->id, 'code' => $code, 'name' => 'Môn ' . $i, 'credits' => 3,
            ]);
            $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);

            // Trọng số ĐIỂM SỐ: chuyên cần 10 · giữa kỳ 40 · cuối kỳ 50 (đúng công thức trường).
            foreach ([['Chuyên cần', 'process', 10], ['GK1', 'process', 40], ['Cuối kỳ', 'final', 50]] as [$n, $cat, $w]) {
                Assessment::create([
                    'syllabus_id' => $syllabus->id, 'name' => $n,
                    'assessment_category' => $cat, 'weight_percent' => $w, 'is_rubric_based' => false,
                ]);
            }

            $this->classes[$code] = CourseClass::create([
                'school_id' => $school->id, 'syllabus_id' => $syllabus->id, 'cohort_id' => $this->cohort->id,
                'lecturer_id' => $lecturerId, 'class_code' => $code . '-2024',
                'academic_year' => '2024-2025', 'semester' => 1,
            ]);
        }
    }

    /** Tạo SV + cho điểm `$courseCount` môn đầu tiên, `$failCount` môn cuối trong số đó bị rớt. */
    private function student(string $code, int $courseCount, int $failCount = 0): Student
    {
        $student = Student::create([
            'school_id' => $this->program->school_id, 'program_id' => $this->program->id,
            'cohort_id' => $this->cohort->id, 'student_code' => $code,
            'full_name' => 'HV ' . $code, 'status' => 'studying',
        ]);

        $i = 0;
        foreach ($this->classes as $class) {
            if ($i >= $courseCount) {
                break;
            }
            // Môn rớt xếp cuối danh sách môn đã học.
            $score = $i >= $courseCount - $failCount ? 4.0 : 8.0;
            foreach (Assessment::where('syllabus_id', $class->syllabus_id)->get() as $assessment) {
                StudentGrade::create([
                    'student_id' => $student->id, 'class_id' => $class->id,
                    'assessment_id' => $assessment->id, 'score' => $score,
                ]);
            }
            $i++;
        }

        return $student;
    }

    public function test_progress_counts_only_passed_courses_as_accumulated_credits(): void
    {
        $this->seedProgram();
        // 11 môn có điểm, 3 môn rớt ⇒ 33 TC đã học nhưng chỉ 24 TC tích lũy (đúng ca 2412HV9036).
        $incomplete = $this->student('2412HV9036', 11, 3);
        $complete = $this->student('2412HV9001', 20);

        $rows = app(StudentProgressService::class)->forProgram($this->program->id, $this->cohort->id);

        $a = $rows[$incomplete->id];
        $this->assertSame(11, $a['courses_graded']);
        $this->assertSame(8, $a['courses_passed']);
        $this->assertEqualsWithDelta(33.0, $a['credits_attempted'], 0.001);
        $this->assertEqualsWithDelta(24.0, $a['credits_earned'], 0.001);   // môn rớt KHÔNG cho tín chỉ
        $this->assertEqualsWithDelta(40.0, $a['completion_percent'], 0.05); // 24/60
        $this->assertFalse($a['is_eligible']);

        $b = $rows[$complete->id];
        $this->assertSame(20, $b['courses_passed']);
        $this->assertEqualsWithDelta(60.0, $b['credits_earned'], 0.001);
        $this->assertEqualsWithDelta(100.0, $b['completion_percent'], 0.05);
        $this->assertTrue($b['is_eligible']);
    }

    public function test_attainment_excludes_students_below_completion_threshold(): void
    {
        $this->seedProgram();
        $incomplete = $this->student('2412HV9036', 11, 3);
        $complete = $this->student('2412HV9001', 20);

        // Cả hai đều có kết quả PLO — SV học dở thậm chí TRƯỢT ngưỡng đạt.
        StudentPloResult::create(['student_id' => $complete->id, 'plo_id' => $this->plo->id, 'achieved_score' => 0.85, 'level' => 3]);
        StudentPloResult::create(['student_id' => $incomplete->id, 'plo_id' => $this->plo->id, 'achieved_score' => 0.40, 'level' => 1]);

        $att = app(ObeAttainmentService::class)->ploAttainment($this->program->id, $this->cohort->id);

        $this->assertSame(2, $att['eligibility']['students_total']);
        $this->assertSame(1, $att['eligibility']['students_eligible']);
        $this->assertSame(1, $att['eligibility']['students_excluded']);
        $this->assertSame(self::REQUIRED_CREDITS, $att['eligibility']['required_credits']);

        // Chỉ SV học đủ được gộp ⇒ 1 SV đo, 1 đạt, 100% — KHÔNG bị SV học dở kéo xuống 50%.
        $row = $att['plos'][0];
        $this->assertSame(1, $row['students_measured']);
        $this->assertSame(1, $row['passed']);
        $this->assertEqualsWithDelta(100.0, $row['attainment_percent'], 0.05);
        $this->assertEqualsWithDelta(0.85, (float) $row['avg_score'], 0.0005);
    }

    public function test_program_without_required_credits_keeps_every_student(): void
    {
        $this->seedProgram(null); // chưa khai số tín chỉ yêu cầu
        $incomplete = $this->student('2412HV9036', 11, 3);
        $complete = $this->student('2412HV9001', 20);

        StudentPloResult::create(['student_id' => $complete->id, 'plo_id' => $this->plo->id, 'achieved_score' => 0.85, 'level' => 3]);
        StudentPloResult::create(['student_id' => $incomplete->id, 'plo_id' => $this->plo->id, 'achieved_score' => 0.40, 'level' => 1]);

        $rows = app(StudentProgressService::class)->forProgram($this->program->id, $this->cohort->id);
        $this->assertNull($rows[$incomplete->id]['completion_percent']);
        $this->assertTrue($rows[$incomplete->id]['is_eligible']);

        $att = app(ObeAttainmentService::class)->ploAttainment($this->program->id, $this->cohort->id);
        $this->assertSame(0, $att['eligibility']['students_excluded']);
        $this->assertSame(2, $att['plos'][0]['students_measured']);
        $this->assertSame(1, $att['plos'][0]['passed']);
    }
}
