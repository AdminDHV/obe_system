<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Clo;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\CurriculumGroup;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCloResult;
use App\Models\User;
use App\Services\OBE\CurriculumGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Khai cấu trúc CTĐT theo khối (bắt buộc / hướng chuyên ngành) và gán hướng cho học viên.
 *
 * Đây là màn hình chỉ dành cho người vận hành (super_admin) — giảng viên không được vào, vì đổi
 * cấu trúc CTĐT là đổi mẫu số của toàn bộ học viên trong ngành.
 */
class CurriculumGroupTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Program $program;

    private Cohort $cohort;

    private User $admin;

    private CurriculumGroup $huongA;

    private CurriculumGroup $huongB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'lecturer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        School::query()->forceDelete();
        $this->school = School::create(['code' => 'S', 'name' => 'Trường', 'is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $this->program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành',
            'school_id' => $this->school->id, 'required_credits' => 12,
        ]);
        $this->cohort = Cohort::create([
            'program_id' => $this->program->id, 'name' => 'K2026', 'start_year' => 2026,
            'school_id' => $this->school->id,
        ]);

        $this->admin = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id]);
        $this->admin->assignRole('super_admin');

        $this->huongA = CurriculumGroup::create([
            'school_id' => $this->school->id, 'program_id' => $this->program->id,
            'code' => 'HA', 'name' => 'Hướng A', 'kind' => CurriculumGroup::KIND_ELECTIVE,
            'required_credits' => 6,
        ]);
        $this->huongB = CurriculumGroup::create([
            'school_id' => $this->school->id, 'program_id' => $this->program->id,
            'code' => 'HB', 'name' => 'Hướng B', 'kind' => CurriculumGroup::KIND_ELECTIVE,
            'required_credits' => 6,
        ]);
    }

    public function test_giang_vien_KHONG_vao_duoc_man_hinh_cau_truc_ctdt(): void
    {
        $gv = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id]);
        $gv->assignRole('lecturer');

        $this->actingAs($gv)->get(route('manager.curriculum.index'))->assertForbidden();
        $this->actingAs($gv)->post(route('manager.curriculum.store'), [
            'program' => $this->program->uuid, 'code' => 'X', 'name' => 'X', 'kind' => 'elective',
        ])->assertForbidden();
    }

    public function test_them_va_xep_hoc_phan_vao_khoi(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manager.curriculum.store'), [
                'program' => $this->program->uuid,
                'code' => 'BB', 'name' => 'Bắt buộc', 'kind' => 'mandatory', 'required_credits' => 6,
            ])
            ->assertRedirect();

        $bb = CurriculumGroup::where('code', 'BB')->firstOrFail();
        $mon = $this->taoMon('C1');

        $this->actingAs($this->admin)
            ->post(route('manager.curriculum.assign-courses'), [
                'program' => $this->program->uuid, 'group' => $bb->uuid, 'course_ids' => [$mon->id],
            ])
            ->assertRedirect();

        $this->assertSame(
            $bb->id,
            (int) DB::table('program_courses')->where('course_id', $mon->id)->value('curriculum_group_id'),
        );
    }

    public function test_xoa_khoi_KHONG_lam_mat_hoc_phan(): void
    {
        $mon = $this->taoMon('C1', $this->huongA);

        $this->actingAs($this->admin)
            ->delete(route('manager.curriculum.destroy', $this->huongA->uuid), ['program' => $this->program->uuid])
            ->assertRedirect();

        $row = DB::table('program_courses')->where('course_id', $mon->id)->first();
        $this->assertNotNull($row, 'Học phần phải còn trong CTĐT');
        $this->assertNull($row->curriculum_group_id, 'Chỉ trở về trạng thái chưa xếp khối');
    }

    public function test_doi_huong_thi_go_huong_cu_khong_cong_don(): void
    {
        $sv = $this->taoSinhVien('SV1');
        $sv->curriculumGroups()->attach($this->huongA->id);

        $this->actingAs($this->admin)
            ->post(route('manager.curriculum.assign-students'), [
                'program' => $this->program->uuid, 'group' => $this->huongB->uuid, 'student_ids' => [$sv->id],
            ])
            ->assertRedirect();

        $this->assertSame([$this->huongB->id], $sv->fresh()->curriculumGroups->pluck('id')->all());
    }

    public function test_suy_huong_tu_hoc_phan_da_hoc(): void
    {
        $monA = $this->taoMon('CA', $this->huongA);
        $monB = $this->taoMon('CB', $this->huongB);

        $a = $this->taoSinhVien('SVA');
        $this->choDiem($a, $monA);

        $b = $this->taoSinhVien('SVB');
        $this->choDiem($b, $monB);

        $ketQua = app(CurriculumGroupService::class)->inferTracks($this->program->id);

        $this->assertSame(2, $ketQua['assigned']);
        $this->assertSame([], $ketQua['skipped_ambiguous']);
        $this->assertSame($this->huongA->id, $a->fresh()->curriculumGroups->first()->id);
        $this->assertSame($this->huongB->id, $b->fresh()->curriculumGroups->first()->id);
    }

    public function test_hoc_o_hai_huong_thi_bo_qua_chu_khong_doan_bua(): void
    {
        $monA = $this->taoMon('CA', $this->huongA);
        $monB = $this->taoMon('CB', $this->huongB);

        $sv = $this->taoSinhVien('SVX');
        $this->choDiem($sv, $monA);
        $this->choDiem($sv, $monB);

        $ketQua = app(CurriculumGroupService::class)->inferTracks($this->program->id);

        $this->assertSame(0, $ketQua['assigned']);
        $this->assertSame(['SVX'], $ketQua['skipped_ambiguous']);
        $this->assertCount(0, $sv->fresh()->curriculumGroups, 'Gán sai hướng làm hỏng mẫu số của cả người đó');
    }

    public function test_suy_huong_giu_nguyen_nguoi_da_co_huong(): void
    {
        $monA = $this->taoMon('CA', $this->huongA);
        $sv = $this->taoSinhVien('SVY');
        $sv->curriculumGroups()->attach($this->huongB->id);   // trường đã khai tay, dù điểm nói khác
        $this->choDiem($sv, $monA);

        $ketQua = app(CurriculumGroupService::class)->inferTracks($this->program->id);

        $this->assertSame(0, $ketQua['assigned']);
        $this->assertSame(1, $ketQua['already']);
        $this->assertSame($this->huongB->id, $sv->fresh()->curriculumGroups->first()->id);
    }

    public function test_bao_dong_khi_khoi_khai_thieu_tin_chi(): void
    {
        $this->taoMon('CA', $this->huongA);   // 3 TC, trong khi khối yêu cầu 6

        $audit = app(CurriculumGroupService::class)->audit($this->program->id);
        $row = collect($audit['groups'])->firstWhere('code', 'HA');

        $this->assertSame(3.0, $row['credits_total']);
        $this->assertSame(3.0, $row['shortfall']);
    }

    public function test_khong_sua_duoc_khoi_cua_truong_khac(): void
    {
        $khac = School::create(['code' => 'S2', 'name' => 'Trường 2', 'is_active' => true]);
        $dept = Department::create(['code' => 'D2', 'name' => 'Khoa 2', 'school_id' => $khac->id]);
        $ct = Program::create([
            'department_id' => $dept->id, 'code' => 'P2', 'name' => 'Ngành 2', 'school_id' => $khac->id,
        ]);
        $group = CurriculumGroup::create([
            'school_id' => $khac->id, 'program_id' => $ct->id,
            'code' => 'Z', 'name' => 'Z', 'kind' => 'elective',
        ]);

        // Người vận hành đang làm việc ở trường 1 ⇒ không được chạm vào khối của trường 2.
        $this->actingAs($this->admin)
            ->withCookie('working_school', (string) $this->school->id)
            ->delete(route('manager.curriculum.destroy', $group->uuid), ['program' => $ct->uuid])
            ->assertNotFound();

        $this->assertNotNull($group->fresh());
    }

    private function taoMon(string $ma, ?CurriculumGroup $group = null): Course
    {
        $course = Course::create([
            'code' => $ma, 'name' => 'Môn ' . $ma, 'credits' => 3, 'school_id' => $this->school->id,
        ]);
        DB::table('program_courses')->insert([
            'program_id' => $this->program->id,
            'course_id' => $course->id,
            'curriculum_group_id' => $group?->id,
            'is_mandatory' => false,
        ]);

        return $course;
    }

    private function taoSinhVien(string $ma): Student
    {
        return Student::create([
            'student_code' => $ma, 'full_name' => $ma, 'program_id' => $this->program->id,
            'cohort_id' => $this->cohort->id, 'school_id' => $this->school->id, 'status' => 'studying',
        ]);
    }

    private function choDiem(Student $sv, Course $course): void
    {
        $syllabus = CourseSyllabus::firstOrCreate(
            ['course_id' => $course->id, 'version' => '1.0'],
            ['is_active' => true],
        );
        $clo = Clo::firstOrCreate(
            ['syllabus_id' => $syllabus->id, 'code' => 'CLO1'],
            ['description' => 'x'],
        );
        $class = CourseClass::firstOrCreate(
            ['class_code' => 'L-' . $course->code],
            [
                'syllabus_id' => $syllabus->id, 'cohort_id' => $this->cohort->id,
                'lecturer_id' => $this->admin->id, 'academic_year' => '2026-2027', 'semester' => 1,
                'max_capacity' => 50, 'school_id' => $this->school->id,
            ],
        );

        StudentCloResult::create([
            'student_id' => $sv->id, 'class_id' => $class->id, 'clo_id' => $clo->id, 'achieved_score' => 8,
        ]);
    }
}
