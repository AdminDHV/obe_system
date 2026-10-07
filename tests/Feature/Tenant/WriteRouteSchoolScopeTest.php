<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Contracts\BelongsToSchool;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Không sửa / xóa được dữ liệu của trường khác qua URL.
 *
 * Rà console thấy 21 route ghi nhận `{uuid}` mà không kiểm trường: `courses.destroy` nhận
 * `Course $course` rồi `->delete()` thẳng; `classes.update` validate theo trường của CHÍNH LỚP BỊ
 * SỬA. Model không có global scope nên route binding lấy được bản ghi của bất kỳ trường nào.
 *
 * Rủi ro không phải leo thang quyền (chỉ người vận hành vào được, mà họ vốn chuyển trường tự do)
 * mà là THAO TÁC NHẦM TRƯỜNG: đang làm ở trường A, mở lại URL cũ, xóa trúng dữ liệu trường B.
 */
class WriteRouteSchoolScopeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(School $school): User
    {
        Role::findOrCreate('super_admin', 'web');
        $u = User::factory()->create(['school_id' => $school->id]);
        $u->assignRole('super_admin');

        return $u;
    }

    public function test_khong_xoa_duoc_du_lieu_cua_truong_khac(): void
    {
        $a = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $b = School::create(['code' => 'S2', 'name' => 'Trường 2']);

        $khoaB = Department::create(['school_id' => $b->id, 'code' => 'D1', 'name' => 'Khoa B']);
        $nganhB = Program::create([
            'school_id' => $b->id, 'department_id' => $khoaB->id, 'code' => 'P1', 'name' => 'Ngành B',
        ]);
        // Mã TRÙNG với trường A — đúng thiết kế đa-trường, và là bẫy dễ lọt nhất.
        $monB = Course::create(['school_id' => $b->id, 'code' => 'C1', 'name' => 'Môn B', 'credits' => 3]);

        $this->actingAs($this->admin($a));
        $q = '?school_id=' . $a->id;

        $this->delete(route('manager.courses.destroy', $monB->uuid) . $q)->assertNotFound();
        $this->delete(route('manager.programs.destroy', $nganhB->uuid) . $q)->assertNotFound();
        $this->delete(route('manager.departments.destroy', $khoaB->uuid) . $q)->assertNotFound();
        $this->put(route('manager.courses.update', $monB->uuid) . $q, ['name' => 'Đổi tên'])->assertNotFound();

        $this->assertDatabaseHas('courses', ['id' => $monB->id, 'name' => 'Môn B']);
        $this->assertDatabaseHas('programs', ['id' => $nganhB->id]);
        $this->assertDatabaseHas('departments', ['id' => $khoaB->id]);
    }

    /** Chặn nhầm cũng là lỗi: trong trường mình thì phải làm được bình thường. */
    public function test_trong_truong_minh_van_xoa_duoc(): void
    {
        $a = School::create(['code' => 'S1', 'name' => 'Trường 1']);
        $mon = Course::create(['school_id' => $a->id, 'code' => 'C1', 'name' => 'Môn A', 'credits' => 3]);

        $this->actingAs($this->admin($a))
            ->delete(route('manager.courses.destroy', $mon->uuid) . '?school_id=' . $a->id)
            ->assertRedirect();

        $this->assertSoftDeleted('courses', ['id' => $mon->id]);
    }

    /**
     * Model dùng trong route manager mà QUÊN khai `BelongsToSchool` thì middleware bỏ qua nó —
     * thủng im lặng. Test này bắt ngay khi có người thêm model mới.
     */
    public function test_moi_model_dung_trong_route_manager_deu_khai_thuoc_truong(): void
    {
        $mienTru = [
            // Quản lý Trường vốn là việc xuyên trường của người vận hành.
            \App\Models\School::class,
        ];

        $thieu = [];
        foreach (Route::getRoutes() as $r) {
            if (! str_starts_with((string) $r->getName(), 'manager.')) {
                continue;
            }
            foreach ($r->signatureParameters() as $p) {
                $lop = $p->getType()?->getName();
                if (! $lop || ! class_exists($lop) || ! is_subclass_of($lop, \Illuminate\Database\Eloquent\Model::class)) {
                    continue;
                }
                if (in_array($lop, $mienTru, true) || is_subclass_of($lop, BelongsToSchool::class)) {
                    continue;
                }
                $thieu[] = $lop;
            }
        }

        $this->assertSame([], array_values(array_unique($thieu)),
            "Model dùng trong route manager nhưng chưa khai BelongsToSchool:\n"
            . implode("\n", array_unique($thieu)));
    }
}
