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
use App\Services\OBE\ClassOutcomeSummaryService;
use App\Services\OBE\ObeRecalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BẢNG 2 của khách — bảng tổng của cả lớp (gộp Bảng 1), cho khoa và CVHT.
 *
 * Đơn vị gộp là `students.class_group` (lớp sinh hoạt) do trường khai; chưa khai thì gộp theo KHÓA.
 * Chế độ "chỉ SV đúng tiến độ trở lên" dùng lại `StudentProgressService::is_eligible`: SV chậm
 * VẪN hiện trong danh sách, chỉ không được tính vào mức đạt của lớp.
 */
class ClassOutcomeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Program $program;
    private Cohort $cohort;
    private User $admin;
    private Plo $plo;
    private Pi $pi;
    private CourseClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'lecturer', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        School::query()->forceDelete();
        $this->school = School::create(['code' => 'S', 'name' => 'Trường', 'is_active' => true]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('super_admin');

        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $this->program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành',
            'school_id' => $this->school->id, 'required_credits' => 6,
        ]);
        $this->cohort = Cohort::create([
            'program_id' => $this->program->id, 'name' => 'K2026', 'start_year' => 2026,
            'school_id' => $this->school->id,
        ]);

        $this->plo = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'x']);
        $this->pi = Pi::create(['plo_id' => $this->plo->id, 'code' => 'PI1', 'description' => 'x', 'is_dummy' => false]);

        // Môn 6 TC — học xong là đủ 100% chương trình.
        $course = Course::create(['code' => 'C1', 'name' => 'Môn', 'credits' => 6, 'school_id' => $this->school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'x']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $this->pi->id, 'mapping_level' => 'R']);

        $this->class = CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $this->cohort->id,
            'lecturer_id' => $this->admin->id, 'class_code' => 'L1',
            'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
            'school_id' => $this->school->id,
        ]);

        $assessments = [];
        foreach (['process' => 40, 'final' => 60] as $category => $weight) {
            $assessment = Assessment::create([
                'syllabus_id' => $syllabus->id, 'name' => $category, 'assessment_category' => $category,
                'weight_percent' => $weight, 'is_rubric_based' => false,
            ]);
            AssessmentCloMapping::create([
                'assessment_id' => $assessment->id, 'clo_id' => $clo->id, 'weight_in_assessment' => 100,
            ]);
            $assessments[] = $assessment;
        }

        // Lớp A: 2 SV học xong môn 6TC ⇒ đúng tiến độ (100%).
        //   A1 điểm 9 ⇒ PI 0.9 đạt · A2 điểm 6 ⇒ PI 0.6 đạt (ngưỡng tỉ lệ 0.5)
        // Lớp A còn A3: RỚT (điểm 3) ⇒ 0 TC tích lũy ⇒ CHẬM, và PI 0.3 KHÔNG đạt.
        $this->makeGraded('A1', 'A', $assessments, 9.0);
        $this->makeGraded('A2', 'A', $assessments, 6.0);
        $this->makeGraded('A3', 'A', $assessments, 3.0);

        // Lớp B: 1 SV, để chứng minh lọc theo lớp sinh hoạt có tác dụng.
        $this->makeGraded('B1', 'B', $assessments, 8.0);

        app(ObeRecalculationService::class)->recalculateScope($this->school->id, $this->program->id, null);
    }

    public function test_gop_theo_lop_sinh_hoat_chi_lay_sv_cua_lop_do(): void
    {
        $data = app(ClassOutcomeSummaryService::class)
            ->forGroup($this->program->id, $this->cohort->id, 'A');

        $this->assertSame('A', $data['group_label']);
        $this->assertSame('class_group', $data['grouped_by']);
        $this->assertSame(3, $data['students_total']);
        $this->assertSame(['A1', 'A2', 'A3'], array_column($data['students'], 'student_code'));
    }

    public function test_mac_dinh_gop_moi_sv_co_ket_qua(): void
    {
        $data = app(ClassOutcomeSummaryService::class)
            ->forGroup($this->program->id, $this->cohort->id, 'A', onTrackOnly: false);

        $this->assertSame(3, $data['students_counted']);

        // A1 0.9 · A2 0.6 đạt; A3 0.3 không ⇒ 2/3 = 66.7%
        $plo = $data['plo_summary'][0];
        $this->assertSame('PLO1', $plo['code']);
        $this->assertSame(3, $plo['students_measured']);
        $this->assertSame(2, $plo['passed']);
        $this->assertEqualsWithDelta(66.7, $plo['attainment_percent'], 0.05);
        $this->assertFalse($plo['met_target']);   // 66.7% < 70%
    }

    public function test_che_do_chi_sv_dung_tien_do_tro_len(): void
    {
        $data = app(ClassOutcomeSummaryService::class)
            ->forGroup($this->program->id, $this->cohort->id, 'A', onTrackOnly: true);

        // A3 rớt môn ⇒ 0/6 TC ⇒ chậm ⇒ bị loại khỏi thống kê nhưng VẪN nằm trong danh sách.
        $this->assertSame(3, $data['students_total']);
        $this->assertSame(2, $data['students_counted']);

        $a3 = collect($data['students'])->firstWhere('student_code', 'A3');
        $this->assertFalse($a3['on_track']);
        $this->assertFalse($a3['counted']);
        $this->assertNotNull($a3['plo']['PLO1'], 'SV chậm vẫn phải thấy kết quả của mình');

        // Chỉ còn A1 + A2, cả hai đều đạt ⇒ 100%
        $plo = $data['plo_summary'][0];
        $this->assertSame(2, $plo['students_measured']);
        $this->assertSame(100.0, $plo['attainment_percent']);
        $this->assertTrue($plo['met_target']);
    }

    public function test_chua_khai_lop_sinh_hoat_thi_gop_theo_khoa(): void
    {
        $service = app(ClassOutcomeSummaryService::class);

        $data = $service->forGroup($this->program->id, $this->cohort->id);
        $this->assertSame('Cả khóa', $data['group_label']);
        // Trường ĐÃ khai lớp (A, B) nhưng người dùng không lọc ⇒ gộp cả khóa. Khác hẳn với
        // trường hợp trường chưa khai lớp nào — báo cáo không được đổ lỗi nhầm cho trường.
        $this->assertSame('cohort_all_groups', $data['grouped_by']);
        $this->assertSame(4, $data['students_total']);   // cả A và B

        $this->assertSame(['A', 'B'], $service->groupsFor($this->program->id, $this->cohort->id));
    }

    public function test_truong_chua_khai_lop_nao_thi_ghi_ro_la_chua_khai(): void
    {
        Student::where('program_id', $this->program->id)->update(['class_group' => null]);

        $data = app(ClassOutcomeSummaryService::class)->forGroup($this->program->id, $this->cohort->id);

        $this->assertSame('cohort_no_groups', $data['grouped_by']);
        $this->assertSame([], app(ClassOutcomeSummaryService::class)->groupsFor($this->program->id));
    }

    public function test_loc_loai_het_sv_thi_bao_KHONG_CO_SO_LIEU_chu_khong_phai_chua_dat(): void
    {
        // Nâng chuẩn tốt nghiệp lên cao để mọi SV đều bị coi là chậm.
        $this->program->update(['required_credits' => 1000]);

        $csv = $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id)
            ->get(route('manager.students.export-class-summary', [
                'program_id' => $this->program->id,
                'on_track' => 1,
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('KHÔNG có sinh viên nào được gộp vào thống kê', $csv);
        $this->assertStringContainsString('KHÔNG CÓ SỐ LIỆU', $csv);
        // Không được kết luận "Chưa đạt" khi mẫu rỗng.
        $this->assertStringNotContainsString('"Chưa đạt"', $csv);
    }

    public function test_xuat_csv_du_ba_khoi_va_cot_dung_tien_do(): void
    {
        $csv = $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id)
            ->get(route('manager.students.export-class-summary', [
                'program_id' => $this->program->id,
                'cohort_id' => $this->cohort->id,
                'class_group' => 'A',
                'on_track' => 1,
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('[I] MỨC ĐẠT CỦA LỚP THEO PLO', $csv);
        $this->assertStringContainsString('[II] MỨC ĐẠT CỦA LỚP THEO PI', $csv);
        $this->assertStringContainsString('[III] TỪNG SINH VIÊN', $csv);
        $this->assertStringContainsString('"Chỉ SV đúng tiến độ trở lên"', $csv);
        $this->assertStringContainsString('"Số SV gộp vào thống kê",2', $csv);
        $this->assertStringContainsString('CHẬM', $csv);
    }

    /**
     * Độ phủ phải nằm ở MỘT MỤC RIÊNG, không nhét vào ô mức đạt.
     * Nếu viết "75.4% (1/3)" chung một ô thì cột mức đạt hết là số và Excel không cộng trung bình
     * được nữa — đúng thứ CVHT làm đầu tiên khi mở file.
     */
    public function test_csv_co_muc_do_phu_rieng_va_cot_huong_chuyen_nganh(): void
    {
        $khoi = \App\Models\CurriculumGroup::create([
            'school_id' => $this->school->id, 'program_id' => $this->program->id,
            'code' => 'BB', 'name' => 'Khối bắt buộc', 'kind' => 'mandatory', 'required_credits' => 6,
        ]);
        \Illuminate\Support\Facades\DB::table('program_courses')->insert([
            'program_id' => $this->program->id,
            'course_id' => \App\Models\Course::where('code', 'C1')->value('id'),
            'curriculum_group_id' => $khoi->id,
            'is_mandatory' => true,
        ]);

        $csv = $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id)
            ->get(route('manager.students.export-class-summary', [
                'program_id' => $this->program->id,
                'cohort_id' => $this->cohort->id,
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('[IV] ĐỘ PHỦ ĐO LƯỜNG', $csv);
        $this->assertStringContainsString('"Hướng chuyên ngành"', $csv);
        $this->assertStringContainsString('1/1', $csv);
        // Mục III vẫn phải là số thuần để còn tính toán được.
        $this->assertStringNotContainsString('% (1/1)', $csv);
    }

    public function test_khong_xuat_duoc_nganh_cua_truong_khac(): void
    {
        $khac = School::create(['code' => 'S2', 'name' => 'Trường 2', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->withUnencryptedCookie('obe_working_school', (string) $khac->id)
            ->get(route('manager.students.export-class-summary', ['program_id' => $this->program->id]))
            ->assertSessionHasErrors('program_id');
    }

    /** @param array<int,Assessment> $assessments */
    private function makeGraded(string $code, string $group, array $assessments, float $score): void
    {
        $student = Student::create([
            'student_code' => $code, 'full_name' => 'SV ' . $code, 'school_id' => $this->school->id,
            'cohort_id' => $this->cohort->id, 'class_group' => $group,
            'program_id' => $this->program->id, 'status' => 'studying',
        ]);

        foreach ($assessments as $assessment) {
            StudentGrade::create([
                'student_id' => $student->id, 'class_id' => $this->class->id,
                'assessment_id' => $assessment->id, 'score' => $score,
            ]);
        }
    }
}
