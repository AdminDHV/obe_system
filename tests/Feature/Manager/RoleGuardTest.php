<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ai được vào console — soát từng vai trò, kể cả những trường hợp dễ quên.
 *
 * Ba chỗ trước đây không test nào chạm tới:
 *   1. Tài khoản bị VÔ HIỆU HOÁ. Màn hình Người dùng có nút "Vô hiệu hoá" ghi `is_active = false`,
 *      nhưng không chỗ nào ĐỌC cờ đó — người bị khoá vẫn đăng nhập và làm việc bình thường.
 *      Vô hiệu hoá là việc làm khi ai đó nghỉ việc hoặc nghi lộ mật khẩu, nên nút bấm mà không có
 *      tác dụng là nguy hiểm hơn không có nút.
 *   2. Tài khoản KHÔNG có vai trò nào — importer có tạo loại này (qtkd3-gv@system.local,
 *      aggregate-12@system.local trên production đều không mang vai trò).
 *   3. Vai trò `student` — portal tự phục vụ đã gỡ, nên không được vào console quản lý.
 */
class RoleGuardTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'lecturer', 'student', 'viewer'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
    }

    private function nguoiDung(?string $vaiTro, bool $hoatDong = true): User
    {
        $u = User::factory()->create([
            'school_id' => $this->school->id,
            'password' => Hash::make('matkhau-manh-123'),
            'is_active' => $hoatDong,
        ]);

        if ($vaiTro !== null) {
            $u->assignRole($vaiTro);
        }

        return $u;
    }

    public function test_tai_khoan_bi_vo_hieu_hoa_khong_dang_nhap_duoc(): void
    {
        $u = $this->nguoiDung('super_admin', hoatDong: false);

        $this->post('/login', ['email' => $u->email, 'password' => 'matkhau-manh-123'])
            ->assertSessionHasErrors('email');

        // assertGuest() nhận TÊN GUARD chứ không nhận thông điệp — truyền chuỗi mô tả vào sẽ
        // báo "Auth guard is not defined" và che mất lỗi thật.
        $this->assertGuest();
    }

    /**
     * Khoá người đang ở TRONG hệ thống mới là tình huống thật: phát hiện lộ mật khẩu thì phải
     * đá ra ngay, không đợi phiên hết hạn.
     */
    public function test_khoa_giua_chung_thi_phien_dang_mo_bi_cat(): void
    {
        $u = $this->nguoiDung('super_admin');

        $this->actingAs($u)->get(route('manager.dashboard.index'))->assertOk();

        $u->forceFill(['is_active' => false])->save();

        $this->actingAs($u->fresh())->get(route('manager.dashboard.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_tai_khoan_van_hoat_dong_thi_dang_nhap_binh_thuong(): void
    {
        $u = $this->nguoiDung('super_admin');

        $this->post('/login', ['email' => $u->email, 'password' => 'matkhau-manh-123']);

        $this->assertAuthenticatedAs($u);
    }

    public static function vaiTroKhongDuocVao(): array
    {
        return [
            'không có vai trò nào' => [null],
            'sinh viên' => ['student'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('vaiTroKhongDuocVao')]
    public function test_vai_tro_khong_duoc_vao_console(?string $vaiTro): void
    {
        $this->actingAs($this->nguoiDung($vaiTro));

        foreach (['manager.dashboard.index', 'manager.students.index', 'manager.classes.index',
            'manager.templates.index'] as $ten) {
            $this->get(route($ten))->assertForbidden();
        }
    }

    /** Giảng viên KHÔNG được chạm quản trị hệ thống, kể cả chỉ đọc. */
    public function test_giang_vien_khong_vao_duoc_quan_tri_he_thong(): void
    {
        $this->actingAs($this->nguoiDung('lecturer'));

        foreach (['manager.users.index', 'manager.schools.index', 'manager.audit-logs.index',
            'manager.students.index', 'manager.programs.index'] as $ten) {
            $this->get(route($ten))->assertForbidden();
        }
    }

    /** Không có route ghi nào để giảng viên tự nâng quyền cho mình. */
    public function test_giang_vien_khong_tu_nang_quyen_duoc(): void
    {
        $gv = $this->nguoiDung('lecturer');
        $this->actingAs($gv);

        $this->put(route('manager.users.update', $gv->uuid), ['roles' => ['super_admin']])
            ->assertForbidden();

        $this->assertFalse($gv->fresh()->hasRole('super_admin'));
    }
}
