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
use App\Models\User;
use App\Services\OBE\CoursePiAttainmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BẢNG 3 của khách — đo PI theo từng học phần, cho giảng viên phụ trách lớp.
 *
 * Ba quyết định được khoá ở đây:
 *  1. Đơn vị là LỚP (không phải môn): điểm chỉ lấy trong lớp đó, vì GV chỉ chịu trách nhiệm lớp mình.
 *  2. Đây là mức đạt PI TRONG học phần, ngưỡng dùng `obe.clo_pass_threshold` (cùng thang 10 với CLO).
 *     Nó KHÔNG cộng lại thành PI chương trình.
 *  3. N = số SV THỰC SỰ có điểm, đếm riêng từng cột — không dùng bảng ghi danh (2/6 importer
 *     không ghi `course_class_student`).
 */
class CoursePiAttainmentTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Cohort $cohort;
    private User $lecturer;
    private CourseClass $lop1;
    private CourseClass $lop2;
    private Pi $pi;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'lecturer', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        School::query()->forceDelete();
        $this->school = School::create(['code' => 'S', 'name' => 'Trường', 'is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành', 'school_id' => $this->school->id,
        ]);
        $this->cohort = Cohort::create([
            'program_id' => $program->id, 'name' => 'K2026', 'start_year' => 2026, 'school_id' => $this->school->id,
        ]);
        $this->lecturer = User::factory()->create(['is_active' => true]);

        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO1', 'description' => 'x']);
        $this->pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1', 'description' => 'x', 'is_dummy' => false]);

        $course = Course::create(['code' => 'C1', 'name' => 'Môn 1', 'credits' => 3, 'school_id' => $this->school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);

        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'x']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $this->pi->id, 'mapping_level' => 'M']);

        $qt = $this->makeAssessment($syllabus, $clo, 'Giữa kỳ', 'process', 40);
        $ck = $this->makeAssessment($syllabus, $clo, 'Cuối kỳ', 'final', 60);

        $this->lop1 = $this->makeClass($syllabus, 'L1');
        $this->lop2 = $this->makeClass($syllabus, 'L2');

        // LỚP 1 — 3 SV. Điểm PI trong môn = QT×0.4 + CK×0.6, ngưỡng đạt 5.
        //   A: 8,8   → 8.0  đạt
        //   B: 4,4   → 4.0  KHÔNG đạt
        //   C: 5,5   → 5.0  đạt (đúng bằng ngưỡng)
        // ⇒ 3 SV đo, 2 đạt = 66.7%, TB = 5.67
        $this->grade($this->lop1, 'A', $qt, 8.0, $ck, 8.0);
        $this->grade($this->lop1, 'B', $qt, 4.0, $ck, 4.0);
        $this->grade($this->lop1, 'C', $qt, 5.0, $ck, 5.0);

        // SV D chỉ dự GIỮA KỲ, vắng cuối kỳ ⇒ cột cuối kỳ N phải là 3, cột giữa kỳ N = 4.
        StudentGrade::create([
            'student_id' => $this->makeStudent('D')->id, 'class_id' => $this->lop1->id,
            'assessment_id' => $qt->id, 'score' => 9.0,
        ]);

        // LỚP 2 — 1 SV đạt, để kiểm bảng tổng gộp nhiều lớp.
        $this->grade($this->lop2, 'E', $qt, 9.0, $ck, 9.0);
    }

    public function test_muc_dat_pi_trong_lop_va_so_sv_theo_tung_cot(): void
    {
        $data = app(CoursePiAttainmentService::class)->forClass($this->lop1);

        $this->assertSame(5.0, $data['pass_threshold']);
        $this->assertSame(70.0, $data['target_percent']);
        $this->assertFalse($data['is_question_scored']);

        $pi = $data['pis'][0];
        $this->assertSame('PI1', $pi['pi_code']);
        $this->assertSame('PLO1', $pi['plo_code']);
        $this->assertSame('M', $pi['mapping_level']);
        $this->assertSame('CLO1', $pi['clo_codes']);

        // SV D có mỗi điểm giữa kỳ ⇒ vẫn ra điểm PI (TB cột quá trình) nên được đo: 4 SV.
        // A 8.0 · B 4.0 · C 5.0 · D 9.0 ⇒ đạt 3/4 = 75%
        $this->assertSame(4, $pi['students_measured']);
        $this->assertSame(3, $pi['passed']);
        $this->assertSame(75.0, $pi['attainment_percent']);
        $this->assertSame(6.5, $pi['avg_score']); // (8+4+5+9)/4
        $this->assertTrue($pi['met_target']);     // 75% ≥ 70%

        // N khác nhau giữa các bài trong CÙNG một môn — đúng như khách lưu ý.
        $qt = collect($pi['assessments'])->firstWhere('assessment_name', 'Giữa kỳ');
        $ck = collect($pi['assessments'])->firstWhere('assessment_name', 'Cuối kỳ');

        $this->assertSame('Quá trình', $qt['category']);
        $this->assertSame(4, $qt['students_sat']);          // A,B,C,D
        $this->assertSame(6.5, $qt['avg_score']);           // (8+4+5+9)/4
        $this->assertSame(3, $qt['passed']);
        $this->assertSame(75.0, $qt['attainment_percent']);

        $this->assertSame('Cuối kỳ', $ck['category']);
        $this->assertSame(3, $ck['students_sat']);          // D vắng
        $this->assertSame(60.0, $ck['weight_percent']);
        $this->assertSame(2, $ck['passed']);
        $this->assertEqualsWithDelta(66.7, $ck['attainment_percent'], 0.05);
    }

    public function test_bang_tong_gop_nhieu_lop_theo_so_sv_thuc_te(): void
    {
        $rollUp = app(CoursePiAttainmentService::class)
            ->rollUp(collect([$this->lop1, $this->lop2]));

        $row = $rollUp[0];
        $this->assertSame(2, $row['classes']);
        $this->assertSame(5, $row['students_measured']);   // 4 + 1
        $this->assertSame(4, $row['passed']);              // 3 + 1
        $this->assertSame(80.0, $row['attainment_percent']);
    }

    public function test_chi_lay_diem_trong_dung_lop_do(): void
    {
        // Lớp 2 chỉ có SV E, không được dính điểm của lớp 1 dù chung đề cương.
        $data = app(CoursePiAttainmentService::class)->forClass($this->lop2);

        $this->assertSame(1, $data['students_total']);
        $this->assertSame(1, $data['pis'][0]['students_measured']);
        $this->assertSame(9.0, $data['pis'][0]['avg_score']);
    }

    public function test_xuat_csv_co_ca_hai_khoi_va_canh_bao_khong_cong_don(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');

        $csv = $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id)
            ->get(route('manager.classes.export-pi', $this->lop1))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('[I] TỔNG HỢP THEO PI', $csv);
        $this->assertStringContainsString('[II] CHI TIẾT THEO BÀI ĐÁNH GIÁ', $csv);
        $this->assertStringContainsString('KHÔNG cộng lại thành mức đạt PI', $csv);
        $this->assertStringContainsString('75.0%', $csv);
        $this->assertStringContainsString('Cuối kỳ', $csv);
    }

    public function test_khong_xuat_duoc_lop_cua_truong_khac(): void
    {
        $khac = School::create(['code' => 'S2', 'name' => 'Trường 2', 'is_active' => true]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $khac->id)
            ->get(route('manager.classes.export-pi', $this->lop1))
            ->assertNotFound();
    }

    private function makeAssessment(CourseSyllabus $syllabus, Clo $clo, string $name, string $category, int $weight): Assessment
    {
        $assessment = Assessment::create([
            'syllabus_id' => $syllabus->id, 'name' => $name, 'assessment_category' => $category,
            'weight_percent' => $weight, 'is_rubric_based' => false,
        ]);
        AssessmentCloMapping::create([
            'assessment_id' => $assessment->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100,
        ]);

        return $assessment;
    }

    private function makeClass(CourseSyllabus $syllabus, string $code): CourseClass
    {
        return CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $this->cohort->id,
            'lecturer_id' => $this->lecturer->id, 'class_code' => $code,
            'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
            'school_id' => $this->school->id,
        ]);
    }

    private function makeStudent(string $code): Student
    {
        return Student::create([
            'student_code' => $code, 'full_name' => 'SV ' . $code, 'school_id' => $this->school->id,
            'cohort_id' => $this->cohort->id,
            'program_id' => $this->cohort->program_id, 'status' => 'studying',
        ]);
    }

    private function grade(CourseClass $class, string $code, Assessment $qt, float $s1, Assessment $ck, float $s2): void
    {
        $student = $this->makeStudent($code);
        foreach ([[$qt, $s1], [$ck, $s2]] as [$assessment, $score]) {
            StudentGrade::create([
                'student_id' => $student->id, 'class_id' => $class->id,
                'assessment_id' => $assessment->id, 'score' => $score,
            ]);
        }
    }
}
