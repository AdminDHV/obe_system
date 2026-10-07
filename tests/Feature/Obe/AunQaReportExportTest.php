<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Exports\AunQaReportExport;
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
use Tests\TestCase;

/**
 * File Excel gửi cho trường phải TỰ hệ thống xuất ra được — đủ để hội đồng lần từ tổng hợp
 * xuống dữ liệu thô, và phải hiện PI/PLO theo ĐÚNG thang (tỉ lệ → %, không phải x/10).
 */
class AunQaReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_has_all_eight_sheets_with_per_student_pi_and_percent_scale(): void
    {
        (new MappingScaleWeightSeeder())->run();

        $school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $dept = Department::create(['school_id' => $school->id, 'code' => 'D1', 'name' => 'Khoa 1']);
        $program = Program::create(['school_id' => $school->id, 'department_id' => $dept->id, 'code' => 'P1', 'name' => 'Ngành 1']);
        $cohort = Cohort::create(['school_id' => $school->id, 'program_id' => $program->id, 'name' => '2024', 'start_year' => 2024]);

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'CĐR 1']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'Chỉ số 1.1', 'is_dummy' => false, 'weight' => 1.0]);

        $course = Course::create(['school_id' => $school->id, 'code' => 'C1', 'name' => 'Môn 1', 'credits' => 3]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'CLO 1']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'M']);

        // Điểm số: chuyên cần 10% (9.0) + cuối kỳ 90% (8.0) → 8.10
        $cc = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Chuyên cần', 'assessment_category' => 'process', 'weight_percent' => 10, 'is_rubric_based' => false]);
        $ck = Assessment::create(['syllabus_id' => $syllabus->id, 'name' => 'Cuối kỳ', 'assessment_category' => 'final', 'weight_percent' => 90, 'is_rubric_based' => false]);

        $class = CourseClass::create([
            'school_id' => $school->id, 'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id,
            'lecturer_id' => User::factory()->create()->id, 'class_code' => 'C1-2024',
            'academic_year' => '2024-2025', 'semester' => 1,
        ]);

        $student = Student::create([
            'school_id' => $school->id, 'program_id' => $program->id, 'cohort_id' => $cohort->id,
            'student_code' => 'SV01', 'full_name' => 'Nguyễn Văn A', 'status' => 'studying',
        ]);

        StudentGrade::create(['student_id' => $student->id, 'class_id' => $class->id, 'assessment_id' => $cc->id, 'score' => 9.0]);
        StudentGrade::create(['student_id' => $student->id, 'class_id' => $class->id, 'assessment_id' => $ck->id, 'score' => 8.0]);
        StudentCloResult::create(['student_id' => $student->id, 'class_id' => $class->id, 'clo_id' => $clo->id, 'achieved_score' => 8.5]);
        StudentPiResult::create(['student_id' => $student->id, 'pi_id' => $pi->id, 'achieved_score' => 0.85]);
        StudentPloResult::create(['student_id' => $student->id, 'plo_id' => $plo->id, 'achieved_score' => 0.85, 'level' => 4]);

        $sheets = (new AunQaReportExport($program->id, $cohort->id))->sheets();

        $this->assertSame([
            'Tổng quan',
            'Mức đạt PLO',
            'Mức đạt PI',
            'Ma trận SV x PLO',
            'Ma trận SV x PI',
            'Kết quả CLO',
            'Điểm tổng kết học phần',
            'Tiến độ tích lũy',
        ], array_map(fn ($s) => $s->title(), $sheets));

        // [5] PI của TỪNG sinh viên — 0.85 phải ra "85%", KHÔNG phải 0.85 (nhìn như trượt).
        $piMatrix = $sheets[4];
        // Cột "Đủ tích lũy" = "—" vì ngành chưa khai số tín chỉ yêu cầu ⇒ không lọc ai.
        $this->assertSame(['MSSV', 'Họ và Tên', 'Đủ tích lũy', 'PI1.1'], $piMatrix->headings());
        $this->assertSame(['SV01', 'Nguyễn Văn A', '—', '85%'], $piMatrix->array()[0]);

        // [6] CLO thang 10 → giữ nguyên số.
        $this->assertSame(['SV01', 'Nguyễn Văn A', 'C1', 'Môn 1', 'CLO1', 8.5], $sheets[5]->array()[0]);

        // [7] Điểm số theo trọng số bài: (9×10 + 8×90) / 100 = 8.10 — độc lập với phép đo ở trên.
        $this->assertSame(['SV01', 'Nguyễn Văn A', 'C1-2024', 'C1', 'Môn 1', 3, 8.1], $sheets[6]->array()[0]);
    }
}
