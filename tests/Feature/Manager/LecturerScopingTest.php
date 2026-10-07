<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PHÂN QUYỀN GIẢNG VIÊN.
 *
 * Trước đây `role:super_admin|lecturer` gác cả console: một tài khoản giảng viên đăng nhập là
 * xoá được ngành, sửa được khung PLO/PI, nhập điểm cho mọi lớp của trường, và tạo/xoá được cả
 * tài khoản giảng viên khác.
 *
 * Nên phần lớn test ở đây kiểm điều KHÔNG được phép, chứ không phải điều được phép.
 */
class LecturerScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $thay;      // giảng viên, dạy lớp A
    private User $co;        // giảng viên khác, dạy lớp B
    private User $vanHanh;   // super_admin
    private CourseClass $lopA;
    private CourseClass $lopB;
    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        School::query()->forceDelete();

        foreach (['super_admin', 'lecturer'] as $ten) {
            Role::firstOrCreate(['name' => $ten, 'guard_name' => 'web']);
        }

        $this->school = School::create(['code' => 'S', 'name' => 'Trường', 'is_active' => true]);
        $dept = Department::create(['code' => 'D', 'name' => 'Khoa', 'school_id' => $this->school->id]);
        $program = Program::create([
            'department_id' => $dept->id, 'code' => 'P', 'name' => 'Ngành',
            'school_id' => $this->school->id,
        ]);
        $cohort = Cohort::create([
            'program_id' => $program->id, 'name' => 'K2023', 'start_year' => 2023,
            'school_id' => $this->school->id,
        ]);

        $this->thay = $this->taoUser('lecturer');
        $this->co = $this->taoUser('lecturer');
        $this->vanHanh = $this->taoUser('super_admin');

        $this->lopA = $this->taoLop($program, $cohort, 'LOP-A', $this->thay);
        $this->lopB = $this->taoLop($program, $cohort, 'LOP-B', $this->co);
    }

    // ── Điều KHÔNG được phép ────────────────────────────────────────────────

    /**
     * Từng route quản trị phải tự chặn. Không dựa vào "giao diện đã ẩn nút" — ẩn nút
     * không phải là phân quyền.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function routeQuanTri(): array
    {
        return [
            'xem danh sách ngành' => ['get', 'manager.programs.index'],
            'xem danh sách sinh viên' => ['get', 'manager.students.index'],
            'xem khung PLO/PI/CLO' => ['get', 'manager.obe.index'],
            'vào trung tâm đo lường' => ['get', 'manager.reports.index'],
            'xem danh sách giảng viên' => ['get', 'manager.lecturers.index'],
            'xem danh sách khoa' => ['get', 'manager.departments.index'],
            'xem niên khóa' => ['get', 'manager.cohorts.index'],
            'xem học phần' => ['get', 'manager.courses.index'],
            'xem đề cương' => ['get', 'manager.syllabi.index'],
            'xem thành phần đánh giá' => ['get', 'manager.assessments.index'],
            'xem người dùng' => ['get', 'manager.users.index'],
            'xem nhật ký hoạt động' => ['get', 'manager.audit-logs.index'],
            'xuất bảng tổng lớp' => ['get', 'manager.students.export-class-summary'],
            'xuất phiếu hàng loạt' => ['get', 'manager.students.export-plo-bulk'],
        ];
    }

    #[DataProvider('routeQuanTri')]
    public function test_giang_vien_KHONG_vao_duoc_route_quan_tri(string $verb, string $route): void
    {
        $this->dangNhapThay()->{$verb}(route($route))->assertForbidden();
    }

    public function test_giang_vien_KHONG_xoa_duoc_nganh_hay_sinh_vien(): void
    {
        $sv = Student::create([
            'student_code' => 'SV1', 'full_name' => 'A', 'school_id' => $this->school->id,
            'status' => 'studying',
        ]);
        $program = Program::first();

        $this->dangNhapThay()->delete(route('manager.programs.destroy', $program))->assertForbidden();
        $this->dangNhapThay()->delete(route('manager.students.destroy', $sv))->assertForbidden();

        $this->assertDatabaseHas('programs', ['id' => $program->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('students', ['id' => $sv->id, 'deleted_at' => null]);
    }

    public function test_giang_vien_KHONG_tao_duoc_tai_khoan_giang_vien_khac(): void
    {
        // Leo thang quyền: tự cấp thêm tài khoản cho mình thì mọi giới hạn phía trên vô nghĩa.
        $this->dangNhapThay()
            ->post(route('manager.lecturers.store'), [
                'name' => 'Người lạ', 'email' => 'la@x.vn', 'staff_code' => 'GV999',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'la@x.vn']);
    }

    public function test_giang_vien_KHONG_sua_duoc_khung_chuan_dau_ra(): void
    {
        $this->dangNhapThay()
            ->post(route('manager.obe.plos.store'), ['program_id' => 1, 'code' => 'PLO9', 'description' => 'x'])
            ->assertForbidden();

        $this->assertDatabaseMissing('plos', ['code' => 'PLO9']);
    }

    public function test_giang_vien_KHONG_nhap_diem_duoc_cho_lop_nguoi_khac(): void
    {
        $this->dangNhapThay()
            ->post(route('manager.classes.grades.import', $this->lopB), [])
            ->assertNotFound();
    }

    public function test_giang_vien_KHONG_xem_duoc_lop_nguoi_khac(): void
    {
        $this->dangNhapThay()->get(route('manager.classes.show', $this->lopB))->assertNotFound();
        $this->dangNhapThay()->get(route('manager.classes.export-pi', $this->lopB))->assertNotFound();
    }

    public function test_bao_404_chu_khong_403_khi_cham_lop_nguoi_khac(): void
    {
        // 403 xác nhận "lớp này có thật" — đủ để dò ra danh sách lớp của cả trường.
        $this->dangNhapThay()
            ->get(route('manager.classes.show', $this->lopB))
            ->assertStatus(404);
    }

    public function test_danh_sach_lop_chi_hien_lop_cua_minh(): void
    {
        $res = $this->dangNhapThay()->get(route('manager.classes.index'))->assertOk();

        $ma = collect($res->viewData('page')['props']['classes']['data'] ?? [])
            ->pluck('class_code')->all();

        $this->assertSame(['LOP-A'], $ma);
    }

    // ── Điều ĐƯỢC phép ──────────────────────────────────────────────────────

    public function test_giang_vien_xem_va_xuat_duoc_lop_cua_minh(): void
    {
        $this->dangNhapThay()->get(route('manager.classes.show', $this->lopA))->assertOk();
        $this->dangNhapThay()->get(route('manager.classes.export-pi', $this->lopA))->assertOk();
        $this->dangNhapThay()->get(route('manager.dashboard.index'))->assertOk();
    }

    // ── Người vận hành không bị ảnh hưởng ───────────────────────────────────

    public function test_super_admin_van_lam_duoc_moi_thu(): void
    {
        $this->dangNhap($this->vanHanh)->get(route('manager.programs.index'))->assertOk();
        $this->dangNhap($this->vanHanh)->get(route('manager.students.index'))->assertOk();
        $this->dangNhap($this->vanHanh)->get(route('manager.classes.show', $this->lopB))->assertOk();

        $res = $this->dangNhap($this->vanHanh)->get(route('manager.classes.index'))->assertOk();
        $ma = collect($res->viewData('page')['props']['classes']['data'] ?? [])
            ->pluck('class_code')->sort()->values()->all();

        $this->assertSame(['LOP-A', 'LOP-B'], $ma, 'Người vận hành phải thấy cả hai lớp');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function taoUser(string $role): User
    {
        $u = User::factory()->create(['is_active' => true, 'school_id' => $this->school->id ?? null]);
        $u->assignRole($role);

        return $u;
    }

    private function taoLop(Program $program, Cohort $cohort, string $ma, User $gv): CourseClass
    {
        $course = Course::create([
            'code' => 'C' . $ma, 'name' => 'Môn ' . $ma, 'credits' => 3,
            'school_id' => $this->school->id,
        ]);
        $syllabus = CourseSyllabus::create([
            'course_id' => $course->id, 'version' => '1.0', 'effective_year' => 2023,
        ]);

        return CourseClass::create([
            'syllabus_id' => $syllabus->id, 'cohort_id' => $cohort->id, 'lecturer_id' => $gv->id,
            'class_code' => $ma, 'academic_year' => '2023-2024', 'semester' => 1,
            'school_id' => $this->school->id,
        ]);
    }

    private function dangNhapThay(): self
    {
        return $this->dangNhap($this->thay);
    }

    private function dangNhap(User $u): self
    {
        return $this->actingAs($u)
            ->withUnencryptedCookie('obe_working_school', (string) $this->school->id);
    }
}
