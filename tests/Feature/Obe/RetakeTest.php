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
use App\Models\User;
use App\Services\OBE\ObeRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SINH VIÊN HỌC LẠI — lấy LẦN HỌC GẦN NHẤT, không trộn với lần rớt.
 *
 * Trước đây engine gộp mọi lớp cùng đề cương nên rớt 3.0 rồi học lại 8.0 ra 5.5. Vừa sai thực tế
 * (bảng điểm lấy lần học lại thay thế) vừa mâu thuẫn với tín chỉ tích lũy — vốn đã coi môn đó ĐẠT.
 * Quy tắc "lần gần nhất" áp cho CẢ phép đo PI lẫn tín chỉ tích lũy để hai nơi không lệch.
 */
class RetakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_lay_lan_hoc_gan_nhat_khong_tron_voi_lan_rot(): void
    {
        $school = School::create(['code' => 'SCH-RT', 'name' => 'Retake']);
        $dept = Department::create(['code' => 'IT', 'name' => 'CNTT', 'school_id' => $school->id]);
        $program = Program::create(['department_id' => $dept->id, 'code' => 'P-RT', 'name' => 'X', 'school_id' => $school->id]);
        $cohort = Cohort::create(['program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026]);
        $lecturer = User::factory()->create();

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'x']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1', 'description' => 'x', 'is_dummy' => false]);

        $student = Student::create([
            'student_code' => 'SV-RT', 'full_name' => 'A', 'school_id' => $school->id,
            'cohort_id' => $cohort->id, 'program_id' => $program->id, 'status' => 'studying',
        ]);

        // MỘT môn (2 TC), MỘT syllabus, HAI lớp: lần đầu rớt 3.0, học lại 8.0.
        $course = Course::create(['code' => 'C-RT', 'name' => 'C', 'credits' => 2, 'school_id' => $school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);

        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'x']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'R']);

        $assessments = [];
        foreach (['process' => 40, 'final' => 60] as $cat => $w) {
            $a = Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $cat, 'assessment_category' => $cat,
                'weight_percent' => $w, 'is_rubric_based' => false,
            ]);
            AssessmentCloMapping::create(['assessment_id' => $a->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100]);
            $assessments[] = $a;
        }

        $mkClass = function (string $code, string $year) use ($syllabus, $cohort, $lecturer, $school) {
            return CourseClass::create([
                'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => $lecturer->id,
                'class_code' => $code, 'academic_year' => $year, 'semester' => 1, 'max_capacity' => 50,
                'school_id' => $school->id,
            ]);
        };

        $lan1 = $mkClass('RT-L1', '2026-2027');   // rớt
        $lan2 = $mkClass('RT-L2', '2027-2028');   // học lại, đạt

        foreach ($assessments as $a) {
            StudentGrade::create(['student_id' => $student->id, 'class_id' => $lan1->id, 'assessment_id' => $a->id, 'score' => 3.0]);
            StudentGrade::create(['student_id' => $student->id, 'class_id' => $lan2->id, 'assessment_id' => $a->id, 'score' => 8.0]);
        }

        app(ObeRecalculationService::class)->recalculateScope($school->id, $program->id, null);

        $piRatio = (float) StudentPiResult::where('student_id', $student->id)->value('achieved_score');

        // Lần 1 rớt 3.0, lần 2 (học lại) 8.0 → PI phải theo lần học lại: 8.0/10 = 0.80.
        // Nếu gộp cả hai lần thì ra 0.55 — chính là lỗi cũ.
        $this->assertEqualsWithDelta(0.80, $piRatio, 0.0005, 'PI phải lấy lần học gần nhất');

        // Tín chỉ cũng chỉ đếm MỘT lần, và là lần học lại (đạt).
        $tienDo = app(\App\Services\OBE\StudentProgressService::class)->forStudent($student);
        $this->assertSame(1, $tienDo['courses_graded'], 'Học lại vẫn chỉ là MỘT môn');
        $this->assertSame(2.0, $tienDo['credits_attempted'], 'Không được nhân đôi tín chỉ');
        $this->assertSame(2.0, $tienDo['credits_earned'], 'Lần học lại đạt ⇒ được cộng tín chỉ');
    }
}
