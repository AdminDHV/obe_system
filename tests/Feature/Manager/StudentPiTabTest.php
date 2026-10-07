<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Assessment;
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
use App\Models\StudentCloResult;
use App\Models\StudentGrade;
use App\Models\StudentPiResult;
use App\Models\StudentPloResult;
use App\Models\User;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Trang chi tiết sinh viên phải trả PI (kèm môn đóng góp) và trả đúng THANG ĐO.
 *
 * Bug đã sửa: PI/PLO của engine WeightedAttainment là TỈ LỆ 0..1, nhưng UI vẽ `score/10`
 * ⇒ 0.8555 hiện thành thanh 8.6% (nhìn như trượt). Nay controller gửi kèm `obe.is_ratio`.
 */
class StudentPiTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_page_returns_pi_results_with_course_breakdown_and_ratio_scale(): void
    {
        (new MappingScaleWeightSeeder())->run(); // I/R/M/A = 1/2/3/3

        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D1', 'name' => 'Khoa 1']);
        $program = Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành 1']);
        $cohort = Cohort::create(['school_id' => $school->id, 'program_id' => $program->id, 'name' => '2024', 'start_year' => 2024]);

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'Chuẩn đầu ra 1']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'Chỉ số 1.1', 'is_dummy' => false, 'weight' => 1.0]);

        $course = Course::create(['school_id' => $school->id, 'code' => 'C1', 'name' => 'Môn 1', 'credits' => 4]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO 1']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);

        $class = CourseClass::create([
            'school_id' => $school->id, 'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id,
            'lecturer_id' => User::factory()->create()->id, 'class_code' => 'C1-2024',
            'academic_year' => '2024-2025', 'semester' => 1,
        ]);

        $student = Student::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id,
            'student_code' => 'SV01', 'full_name' => 'Nguyễn Văn A', 'status' => 'studying',
        ]);
        $class->students()->attach($student->id);

        // Bài đánh giá có thật thì engine mới chấm được điểm PI trong môn. Fixture cũ chỉ có dòng
        // StudentCloResult trơ trọi — trạng thái KHÔNG thể xảy ra qua pipeline thật, vì chính
        // engine cũng bỏ qua môn đó và sẽ không sinh nổi dòng StudentPiResult bên dưới.
        $assessment = Assessment::create([
            'syllabus_id' => $syllabus->id, 'name' => 'Cuối kỳ',
            'assessment_category' => 'final', 'weight_percent' => 100, 'is_rubric_based' => false,
        ]);
        $assessment->cloMappings()->create(['clo_id' => $clo->id, 'weight_in_assessment' => 100]);
        StudentGrade::create([
            'student_id' => $student->id, 'class_id' => $class->id,
            'assessment_id' => $assessment->id, 'score' => 8.5,
        ]);

        StudentCloResult::create(['student_id' => $student->id, 'class_id' => $class->id, 'clo_id' => $clo->id, 'achieved_score' => 8.5]);
        StudentPiResult::create(['student_id' => $student->id, 'pi_id' => $pi->id, 'achieved_score' => 0.85]);
        StudentPloResult::create(['student_id' => $student->id, 'plo_id' => $plo->id, 'achieved_score' => 0.85, 'level' => 4]);

        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->get(route('manager.students.show', $student->uuid) . '?school_id=' . $school->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Manager/Students/Show')
                // Thang đo: tỉ lệ 0..1, ngưỡng 0.5 → UI vẽ 85%, KHÔNG phải 8.5%.
                ->where('obe.is_ratio', true)
                ->where('obe.threshold', 0.5)
                ->where('ploResults.0.passed', true)
                // PI có số + giải trình đo ở môn nào.
                ->where('piResults.0.pi_code', 'PI1.1')
                ->where('piResults.0.passed', true)
                ->where('piResults.0.courses.0.course_code', 'C1')
                ->where('piResults.0.courses.0.credits', 4)
                ->where('piResults.0.courses.0.level', 'M')
                ->where('stats.pi_achieved', 1)
                ->where('stats.pi_total', 1)
            );
    }
}
