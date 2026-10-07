<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Course;
use App\Models\CourseSyllabus;
use App\Models\Department;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Trang Khung CTĐT (PLO/PI/CLO/mapping) phải bị kẹp theo TRƯỜNG ĐANG LÀM VIỆC.
 *
 * Trước đây `ObeController` không lọc `school_id` ở bất kỳ endpoint nào trong 13 endpoint:
 * `index` truy vấn toàn hệ thống, còn store/update/destroy nhận thẳng id cha từ payload và
 * bind model theo uuid mà không kiểm chủ sở hữu. Hệ quả: một GV có chức danh chứa "Trưởng"
 * (điều kiện DUY NHẤT của `authorizeManager`) đọc, sửa và xoá được khung của trường khác.
 *
 * Mức thiệt hại thực tế:
 *  - PLO/PI/CLO dùng SoftDeletes ⇒ xoá là "biến mất khỏi mọi truy vấn", nên PLO đó thôi được đo
 *    và báo cáo của trường kia lặng lẽ thiếu một chuẩn đầu ra. Còn dòng trong DB, khôi phục được.
 *  - `clo_pi_mappings` XOÁ CỨNG ⇒ mất hẳn, mà mapping chính là thứ quyết định CLO nào đo PI nào.
 *  - `update` không liên quan soft delete: có thể đổi mã PLO của trường khác, hoặc trỏ nó sang
 *    ngành của mình.
 *
 * PLO/PI/CLO không có cột `school_id`, trường suy qua quan hệ:
 *   PLO → ngành → trường · PI → PLO → ngành → trường · CLO → đề cương → môn → trường
 */
class ObeFrameworkScopingTest extends TestCase
{
    use RefreshDatabase;

    private School $a;
    private School $b;
    private User $admin;

    /** @var array<string, array<string, mixed>> */
    private array $chains = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'lecturer', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        School::query()->forceDelete();
        $this->a = School::create(['code' => 'AAA', 'name' => 'Trường A', 'is_active' => true]);
        $this->b = School::create(['code' => 'BBB', 'name' => 'Trường B', 'is_active' => true]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('super_admin');

        $this->chains['a'] = $this->chain($this->a);
        $this->chains['b'] = $this->chain($this->b);
    }

    public function test_index_chi_tra_khung_cua_truong_dang_lam_viec(): void
    {
        $this->workingIn($this->a)->get(route('manager.obe.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('plos', 1)
                ->where('plos.0.code', 'PLO-' . $this->a->id)
                ->has('pis', 1)
                ->has('clos', 1)
                ->has('mappings', 1)
                ->has('programs', 1)
                ->has('courses', 1)
                ->has('syllabi', 1));
    }

    public function test_khong_sua_duoc_plo_cua_truong_khac(): void
    {
        $ploB = $this->chains['b']['plo'];

        $this->workingIn($this->a)
            ->put(route('manager.obe.plos.update', $ploB), [
                'program_id' => $this->chains['a']['program']->id,
                'code' => 'BỊ-CHIẾM',
                'description' => 'x',
            ])
            ->assertNotFound();

        $this->assertSame('PLO-' . $this->b->id, $ploB->fresh()->code);
    }

    public function test_khong_xoa_duoc_plo_cua_truong_khac(): void
    {
        $ploB = $this->chains['b']['plo'];

        $this->workingIn($this->a)
            ->delete(route('manager.obe.plos.destroy', $ploB))
            ->assertNotFound();

        // `deleted_at` phải còn NULL — soft-delete PLO là đủ để nó rơi khỏi phép đo.
        $this->assertDatabaseHas('plos', ['id' => $ploB->id, 'deleted_at' => null]);
    }

    public function test_khong_xoa_duoc_clo_va_mapping_cua_truong_khac(): void
    {
        $this->workingIn($this->a)
            ->delete(route('manager.obe.clos.destroy', $this->chains['b']['clo']))
            ->assertNotFound();

        $this->workingIn($this->a)
            ->delete(route('manager.obe.mappings.destroy', $this->chains['b']['mapping']))
            ->assertNotFound();

        $this->assertDatabaseHas('clos', ['id' => $this->chains['b']['clo']->id, 'deleted_at' => null]);
        // Mapping xoá cứng — mất là mất hẳn, nên chỉ cần còn dòng là đủ.
        $this->assertDatabaseHas('clo_pi_mappings', ['id' => $this->chains['b']['mapping']->id]);
    }

    public function test_khong_tao_duoc_plo_vao_nganh_cua_truong_khac(): void
    {
        $this->workingIn($this->a)
            ->post(route('manager.obe.plos.store'), [
                'program_id' => $this->chains['b']['program']->id,
                'code' => 'PLO-LẠ',
                'description' => 'x',
            ])
            ->assertSessionHasErrors('program_id');

        $this->assertDatabaseMissing('plos', ['code' => 'PLO-LẠ']);
    }

    public function test_khong_tao_duoc_mapping_tro_sang_truong_khac(): void
    {
        $this->workingIn($this->a)
            ->post(route('manager.obe.mappings.store'), [
                'clo_id' => $this->chains['b']['clo']->id,
                'pi_id' => $this->chains['b']['pi']->id,
                'mapping_level' => 'R',
            ])
            ->assertSessionHasErrors(['clo_id', 'pi_id']);
    }

    /**
     * Kịch bản tấn công thật: trưởng bộ môn của trường A. `authorizeManager` cho qua vì chức danh
     * có chữ "Trưởng", nhưng WorkingSchool kẹp cứng vào `users.school_id` nên không forge được
     * ?school_id sang trường B.
     */
    public function test_truong_bo_mon_bi_kep_vao_truong_cua_minh(): void
    {
        $head = User::factory()->create([
            'is_active' => true,
            'school_id' => $this->a->id,
            'department_id' => $this->chains['a']['dept']->id,
            'position' => 'Trưởng bộ môn',
        ]);
        $head->assignRole('lecturer');

        // Khung PLO/PI/CLO giờ CHỈ người vận hành (super_admin) chạm được — bảo đảm mạnh hơn
        // "kẹp đúng trường": trưởng bộ môn không vào được khung của bất kỳ trường nào, kể cả
        // trường mình. Xem LecturerScopingTest cho toàn cảnh danh sách trắng.
        $this->actingAs($head)
            ->get(route('manager.obe.index', ['school_id' => $this->b->id]))
            ->assertForbidden();

        $this->actingAs($head)
            ->delete(route('manager.obe.plos.destroy', $this->chains['b']['plo']))
            ->assertForbidden();

        $this->assertDatabaseHas('plos', ['id' => $this->chains['b']['plo']->id, 'deleted_at' => null]);
    }

    public function test_van_thao_tac_binh_thuong_trong_truong_minh(): void
    {
        $this->workingIn($this->a)
            ->post(route('manager.obe.plos.store'), [
                'program_id' => $this->chains['a']['program']->id,
                'code' => 'PLO-MỚI',
                'description' => 'Chuẩn đầu ra mới',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('plos', ['code' => 'PLO-MỚI']);

        $this->workingIn($this->a)
            ->delete(route('manager.obe.plos.destroy', $this->chains['a']['plo']))
            ->assertRedirect();

        $this->assertSoftDeleted('plos', ['id' => $this->chains['a']['plo']->id]);
    }

    private function workingIn(School $school): self
    {
        $this->actingAs($this->admin)->withUnencryptedCookie('obe_working_school', (string) $school->id);

        return $this;
    }

    /** Một bộ khung đầy đủ cho 1 trường: ngành → PLO → PI, môn → đề cương → CLO, và mapping. */
    private function chain(School $school): array
    {
        $dept = Department::create(['code' => 'K' . $school->id, 'name' => 'Khoa', 'school_id' => $school->id]);
        $program = Program::create([
            'department_id' => $dept->id, 'code' => 'P' . $school->id, 'name' => 'Ngành', 'school_id' => $school->id,
        ]);
        $plo = Plo::create(['program_id' => $program->id, 'code' => 'PLO-' . $school->id, 'description' => 'x']);
        $pi = Pi::create(['plo_id' => $plo->id, 'code' => 'PI-' . $school->id, 'description' => 'x', 'is_dummy' => false]);

        $course = Course::create(['code' => 'C' . $school->id, 'name' => 'Môn', 'credits' => 3, 'school_id' => $school->id]);
        $syllabus = CourseSyllabus::create(['course_id' => $course->id, 'version' => '1.0', 'is_active' => true]);
        $clo = Clo::create(['syllabus_id' => $syllabus->id, 'code' => 'CLO-' . $school->id, 'description' => 'x']);

        $mapping = CloPiMapping::create(['clo_id' => $clo->id, 'pi_id' => $pi->id, 'mapping_level' => 'R']);

        return compact('dept', 'program', 'plo', 'pi', 'course', 'syllabus', 'clo', 'mapping');
    }
}
