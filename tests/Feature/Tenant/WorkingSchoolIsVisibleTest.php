<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Đăng nhập vào là phải biết mình đang ở TRƯỜNG NÀO và VAI TRÒ GÌ.
 *
 * Header có bộ chọn "Trường đang làm việc", nhưng nó tự ẩn khi chỉ có ≤1 trường chuyển được —
 * mà giảng viên / cố vấn học tập bị kẹp cứng vào đúng một trường, nên MỌI người dùng không phải
 * quản trị hệ thống đăng nhập vào đều không thấy dấu hiệu nào về trường của mình. Với sản phẩm
 * đa-trường thì thiếu cái đó là thiếu thứ cơ bản nhất: cả đợt tách dữ liệu theo trường cũng chỉ
 * nhằm tránh làm nhầm trường.
 */
class WorkingSchoolIsVisibleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'lecturer', 'student', 'viewer'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_giang_vien_thay_ten_truong_cua_minh(): void
    {
        $a = School::create(['code' => 'VIS-A', 'name' => 'Trường A', 'is_active' => true]);
        School::create(['code' => 'VIS-B', 'name' => 'Trường B', 'is_active' => true]);

        $gv = User::factory()->create(['is_active' => true, 'school_id' => $a->id]);
        $gv->assignRole('lecturer');

        $this->actingAs($gv)->get(route('manager.dashboard.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workingSchoolName', 'Trường A')
                ->where('workingSchoolId', $a->id)
                // Chỉ trường của mình mới chuyển được — không được lộ trường khác.
                ->where('workingSchools', fn ($ds) => count($ds) === 1 && $ds[0]['name'] === 'Trường A'));
    }

    public function test_truong_bi_tat_van_hien_ten(): void
    {
        $a = School::create(['code' => 'VIS-OFF', 'name' => 'Trường đã tắt', 'is_active' => false]);

        $gv = User::factory()->create(['is_active' => true, 'school_id' => $a->id]);
        $gv->assignRole('lecturer');

        $this->actingAs($gv)->get(route('manager.dashboard.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Không chuyển được (trường đã tắt) nhưng VẪN đang làm việc trong đó.
                ->where('workingSchools', [])
                ->where('workingSchoolName', 'Trường đã tắt'));
    }

    public function test_quan_tri_he_thong_van_chuyen_duoc_moi_truong(): void
    {
        School::create(['code' => 'VIS-1', 'name' => 'Trường 1', 'is_active' => true]);
        School::create(['code' => 'VIS-2', 'name' => 'Trường 2', 'is_active' => true]);

        $qt = User::factory()->create(['is_active' => true]);
        $qt->assignRole('super_admin');

        $this->actingAs($qt)->get(route('manager.dashboard.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workingSchools', fn ($ds) => count(array_intersect(
                    ['Trường 1', 'Trường 2'],
                    collect($ds)->pluck('name')->all(),
                )) === 2)
                ->where('workingSchoolName', fn ($ten) => is_string($ten) && $ten !== ''));
    }

    public function test_vai_tro_duoc_gui_xuong_de_hien_o_thanh_ben(): void
    {
        $a = School::create(['code' => 'VIS-R', 'name' => 'Trường R', 'is_active' => true]);
        $nv = User::factory()->create(['is_active' => true, 'school_id' => $a->id]);
        $nv->assignRole('viewer');

        $this->actingAs($nv)->get(route('manager.dashboard.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.roles', ['viewer']));
    }
}
