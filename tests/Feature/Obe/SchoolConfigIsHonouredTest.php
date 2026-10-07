<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

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
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\OBE\ObeConfig;
use App\Services\OBE\ObeRecalculationService;
use Database\Seeders\MappingScaleWeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hệ số riêng của TRƯỜNG phải được áp dụng dù gọi tính lại theo phạm vi nào.
 *
 * Bản cũ bọc cả vòng lặp trong `ObeConfig::forSchool($schoolId, …)` với $schoolId là THAM SỐ của
 * lệnh. Gọi `obe:recalculate --program=17` (không kèm --school) thì $schoolId = null và toàn bộ
 * hệ số riêng của trường bị bỏ qua — chạy bằng mặc định mà không báo gì, ra số sai lặng lẽ.
 * Đây chính là cách production ra 475/714 ô khớp thay vì 714/714.
 */
class SchoolConfigIsHonouredTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_weights_apply_when_scope_is_given_by_program_only(): void
    {
        (new MappingScaleWeightSeeder())->run();

        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D1', 'name' => 'Khoa 1']);
        $program = Program::create([
            'school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành 1',
        ]);
        $cohort = Cohort::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'name' => '2026', 'start_year' => 2026,
        ]);

        // Trường chấm 10% chuyên cần + 40% giữa kỳ + 50% cuối kỳ ⇒ đo lường chia 4/9 : 5/9.
        SystemSetting::create(['school_id' => $school->id, 'key' => 'obe.process_weight', 'value' => '0.4']);
        SystemSetting::create(['school_id' => $school->id, 'key' => 'obe.final_weight', 'value' => '0.5']);
        ObeConfig::flush();

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'CĐR 1']);
        $pi = Pi::create([
            'plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'Chỉ số 1.1',
            'is_dummy' => false, 'weight' => 1.0,
        ]);

        $course = Course::create(['school_id' => $school->id, 'code' => 'C1', 'name' => 'Môn 1', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO 1']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);

        foreach ([['Giữa kỳ', 'process', 40], ['Cuối kỳ', 'final', 50]] as [$name, $cat, $w]) {
            Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $name,
                'assessment_category' => $cat, 'weight_percent' => $w, 'is_rubric_based' => false,
            ])->cloMappings()->create(['clo_id' => $clo->id, 'weight_in_assessment' => 100]);
        }

        $class = CourseClass::create([
            'school_id' => $school->id, 'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id,
            'lecturer_id' => User::factory()->create()->id, 'class_code' => 'C1-2026',
            'academic_year' => '2026-2027', 'semester' => 1,
        ]);

        $student = Student::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id,
            'student_code' => 'SV01', 'full_name' => 'Nguyễn Văn A', 'status' => 'studying',
        ]);
        $class->students()->attach($student->id);

        foreach (Assessment::where('syllabus_id', $syllabus->id)->get() as $a) {
            StudentGrade::create([
                'student_id' => $student->id, 'class_id' => $class->id, 'assessment_id' => $a->id,
                'score' => $a->assessment_category === 'final' ? 9.0 : 6.0,
            ]);
        }

        // Phạm vi CHỈ theo ngành — đúng cách production đã chạy và ra số sai.
        app(ObeRecalculationService::class)->recalculateScope(null, $program->id, null);

        $clo1 = (float) StudentCloResult::where('student_id', $student->id)->value('achieved_score');

        // Hệ số trường: 6×4/9 + 9×5/9 = 7.6667. Mặc định 0.4/0.6 sẽ ra 7.8 — phải KHÁC.
        $this->assertEqualsWithDelta(6.0 * 4 / 9 + 9.0 * 5 / 9, $clo1, 0.0005);
        $this->assertNotEqualsWithDelta(6.0 * 0.4 + 9.0 * 0.6, $clo1, 0.01);
    }
}
