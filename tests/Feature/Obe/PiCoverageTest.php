<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\CurriculumGroup;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\User;
use App\Services\OBE\PiCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Độ phủ đo lường "PI1.1 (1/3)".
 *
 * Yêu cầu của trường: mức đạt PI hiện ngay sau mỗi học kỳ, nhưng phải kèm mẫu số để biết đã đo
 * qua bao nhiêu học phần trên tổng số của LỘ TRÌNH RIÊNG học viên đó — không phải tổng của cả
 * chương trình, vì mỗi học viên chỉ học một hướng chuyên ngành.
 *
 * Bối cảnh dựng trong test: 2 học phần chung + 2 hướng chuyên ngành, mỗi hướng 1 học phần; cả 4
 * học phần đều đo PI1. Học viên hướng A phải thấy mẫu số 3 (2 chung + 1 của hướng A), tuyệt đối
 * không phải 4.
 *
 * Kết quả CLO được ghi thẳng vào `student_clo_results` — đây chính là dữ liệu mà bảng chi tiết PI
 * của học viên đọc, nên test bám đúng hợp đồng của service thay vì chạy lại cả pipeline chấm điểm.
 */
class PiCoverageTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Program $program;

    private Cohort $cohort;

    private Pi $pi;

    private CurriculumGroup $batBuoc;

    private CurriculumGroup $huongA;

    private CurriculumGroup $huongB;

    /** @var array<string, array{course:Course, syllabus:CourseSyllabus, clo:Clo}> */
    private array $mon = [];

    protected function setUp(): void
    {
        parent::setUp();

        School::query()->forceDelete();
        $this->school = School::create(['code' => 'S', 'name' => 'Trường', 'is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $this->program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành',
            'school_id' => $this->school->id, 'required_credits' => 9,
        ]);
        $this->cohort = Cohort::create([
            'program_id' => $this->program->id, 'name' => 'K2026', 'start_year' => 2026,
            'school_id' => $this->school->id,
        ]);

        $plo = Plo::create(['program_id' => $this->program->id, 'code' => 'PLO1', 'description' => 'x']);
        $this->pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI1.1', 'description' => 'x', 'is_dummy' => false]);

        $this->batBuoc = CurriculumGroup::create([
            'school_id' => $this->school->id, 'program_id' => $this->program->id,
            'code' => 'BB', 'name' => 'Bắt buộc', 'kind' => CurriculumGroup::KIND_MANDATORY,
            'required_credits' => 6, 'sort_order' => 0,
        ]);
        $this->huongA = CurriculumGroup::create([
            'school_id' => $this->school->id, 'program_id' => $this->program->id,
            'code' => 'HA', 'name' => 'Hướng A', 'kind' => CurriculumGroup::KIND_ELECTIVE,
            'required_credits' => 3, 'sort_order' => 1,
        ]);
        $this->huongB = CurriculumGroup::create([
            'school_id' => $this->school->id, 'program_id' => $this->program->id,
            'code' => 'HB', 'name' => 'Hướng B', 'kind' => CurriculumGroup::KIND_ELECTIVE,
            'required_credits' => 3, 'sort_order' => 2,
        ]);

        $this->taoMon('C1', $this->batBuoc);
        $this->taoMon('C2', $this->batBuoc);
        $this->taoMon('CA', $this->huongA);
        $this->taoMon('CB', $this->huongB);
    }

    public function test_mau_so_chi_dem_lo_trinh_cua_chinh_hoc_vien(): void
    {
        $sv = $this->taoSinhVien('SV1');
        $sv->curriculumGroups()->attach($this->huongA->id);

        // Xong học kỳ 1: mới có điểm môn chung C1.
        $this->choDiem($sv, 'C1');

        $phu = app(PiCoverageService::class)->forStudent($sv);

        $this->assertTrue($phu['declared']);
        $this->assertFalse($phu['pending_choice']);
        $this->assertSame(['Hướng A'], $phu['chosen_groups']);
        // 2 môn chung + 1 môn hướng A = 3. KHÔNG được là 4 (môn hướng B bạn này không học).
        $this->assertSame(['done' => 1, 'total' => 3], $phu['pis']['PI1.1']);
    }

    public function test_hoc_them_mon_thi_tu_so_tang(): void
    {
        $sv = $this->taoSinhVien('SV2');
        $sv->curriculumGroups()->attach($this->huongA->id);
        $this->choDiem($sv, 'C1');
        $this->choDiem($sv, 'CA');

        $phu = app(PiCoverageService::class)->forStudent($sv);

        $this->assertSame(['done' => 2, 'total' => 3], $phu['pis']['PI1.1']);
    }

    public function test_hai_huong_khac_nhau_co_mau_so_rieng(): void
    {
        $a = $this->taoSinhVien('SVA');
        $a->curriculumGroups()->attach($this->huongA->id);
        $this->choDiem($a, 'CA');

        $b = $this->taoSinhVien('SVB');
        $b->curriculumGroups()->attach($this->huongB->id);
        $this->choDiem($b, 'CB');

        $phu = app(PiCoverageService::class)->forStudents(
            $this->program->id,
            Student::whereIn('student_code', ['SVA', 'SVB'])->get(),
        );

        $this->assertSame(['done' => 1, 'total' => 3], $phu[$a->id]['pis']['PI1.1']);
        $this->assertSame(['done' => 1, 'total' => 3], $phu[$b->id]['pis']['PI1.1']);
    }

    public function test_chua_chon_huong_thi_chi_dem_phan_bat_buoc_va_bao_ro(): void
    {
        // Đại học chọn hướng từ năm 3 ⇒ hai năm đầu học viên chưa có hướng nào.
        $sv = $this->taoSinhVien('SV3');
        $this->choDiem($sv, 'C1');

        $phu = app(PiCoverageService::class)->forStudent($sv);

        $this->assertTrue($phu['pending_choice'], 'Phải báo rõ là mẫu số chưa đủ vì chưa chọn hướng');
        $this->assertSame([], $phu['chosen_groups']);
        $this->assertSame(['done' => 1, 'total' => 2], $phu['pis']['PI1.1']);
    }

    public function test_nhom_mo_5_chon_4_dem_theo_mon_da_dang_ky(): void
    {
        // Hướng A mở thêm 1 môn nữa (tổng 6 TC) nhưng chỉ yêu cầu 3 TC ⇒ học viên chọn 1 trong 2.
        $this->taoMon('CA2', $this->huongA);

        $sv = $this->taoSinhVien('SV4');
        $sv->curriculumGroups()->attach($this->huongA->id);
        $this->dangKy($sv, 'CA2');
        $this->choDiem($sv, 'CA2');

        $phu = app(PiCoverageService::class)->forStudent($sv);

        // 2 môn chung + đúng 1 môn đã đăng ký trong nhóm = 3, không phải 4.
        $this->assertSame(['done' => 1, 'total' => 3], $phu['pis']['PI1.1']);
    }

    public function test_truong_chua_khai_nhom_thi_khong_hien_do_phu(): void
    {
        DB::table('program_courses')->update(['curriculum_group_id' => null]);
        CurriculumGroup::query()->delete();

        $sv = $this->taoSinhVien('SV5');
        $this->choDiem($sv, 'C1');

        $phu = app(PiCoverageService::class)->forStudent($sv);

        $this->assertFalse($phu['declared'], 'Thà không hiện còn hơn hiện một mẫu số sai');
        $this->assertSame([], $phu['pis']);
    }

    public function test_do_phu_cap_chuong_trinh_cho_giang_vien(): void
    {
        $this->assertSame(['PI1.1' => 4], app(PiCoverageService::class)->forProgram($this->program->id));
    }

    private function taoMon(string $ma, CurriculumGroup $group): void
    {
        $course = Course::create([
            'code' => $ma, 'name' => 'Môn ' . $ma, 'credits' => 3, 'school_id' => $this->school->id,
        ]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO1', 'description' => 'x']);
        CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $this->pi->id, 'mapping_level' => 'R']);

        DB::table('program_courses')->insert([
            'program_id' => $this->program->id,
            'course_id' => $course->id,
            'curriculum_group_id' => $group->id,
            'is_mandatory' => $group->kind === CurriculumGroup::KIND_MANDATORY,
        ]);

        $this->mon[$ma] = ['course' => $course, 'syllabus' => $syllabus, 'clo' => $clo];
    }

    private function taoSinhVien(string $ma): Student
    {
        return Student::create([
            'student_code' => $ma, 'full_name' => $ma, 'program_id' => $this->program->id,
            'cohort_id' => $this->cohort->id, 'school_id' => $this->school->id, 'status' => 'studying',
        ]);
    }

    private function lop(string $ma): CourseClass
    {
        return CourseClass::firstOrCreate(
            ['class_code' => 'L-' . $ma],
            [
                'syllabus_id' => $this->mon[$ma]['syllabus']->id,
                'cohort_id' => $this->cohort->id,
                'lecturer_id' => User::factory()->create()->id,
                'academic_year' => '2026-2027', 'semester' => 1, 'max_capacity' => 50,
                'school_id' => $this->school->id,
            ],
        );
    }

    private function dangKy(Student $sv, string $ma): void
    {
        DB::table('course_class_student')->insertOrIgnore([
            'class_id' => $this->lop($ma)->id, 'student_id' => $sv->id, 'status' => 'active',
        ]);
    }

    private function choDiem(Student $sv, string $ma, float $diem = 8.0): void
    {
        $this->dangKy($sv, $ma);
        StudentCloResult::create([
            'student_id' => $sv->id,
            'class_id' => $this->lop($ma)->id,
            'clo_id' => $this->mon[$ma]['clo']->id,
            'achieved_score' => $diem,
        ]);
    }
}
