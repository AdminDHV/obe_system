<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\MappingScaleWeight;
use App\Models\School;
use App\Models\SystemSetting;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tham số đo phải TÁCH ĐƯỢC THEO TRƯỜNG.
 *
 * Trước đây `system_settings` và `mapping_scale_weights` dùng chung toàn hệ thống — trường A chỉnh
 * ngưỡng đạt là mọi trường đổi theo. Nay `school_id` NULL = mặc định dùng chung, có `school_id` =
 * dòng riêng ĐÈ lên mặc định. Không khai riêng ⇒ hành vi y hệt trước.
 */
class PerSchoolObeConfigTest extends TestCase
{
    use RefreshDatabase;

    private School $a;
    private School $b;

    protected function setUp(): void
    {
        parent::setUp();
        ObeConfig::flush();
        School::query()->forceDelete();
        SystemSetting::create(['key' => 'obe.clo_pass_threshold', 'value' => '5', 'school_id' => null]);
        $this->a = School::create(['code' => 'A', 'name' => 'Trường A', 'is_active' => true]);
        $this->b = School::create(['code' => 'B', 'name' => 'Trường B', 'is_active' => true]);
    }

    public function test_khong_khai_rieng_thi_moi_truong_dung_mac_dinh(): void
    {
        SystemSetting::create(['key' => 'obe.clo_pass_threshold', 'value' => '5', 'school_id' => null]);
        ObeConfig::flush();

        $a = ObeConfig::forSchool($this->a->id, fn () => ObeConfig::cloPassThreshold());
        $b = ObeConfig::forSchool($this->b->id, fn () => ObeConfig::cloPassThreshold());

        $this->assertSame(5.0, $a);
        $this->assertSame(5.0, $b);
    }

    public function test_truong_khai_rieng_thi_de_len_mac_dinh_va_KHONG_lam_truong_khac_doi(): void
    {
        SystemSetting::create(['key' => 'obe.clo_pass_threshold', 'value' => '5', 'school_id' => null]);
        SystemSetting::create(['key' => 'obe.clo_pass_threshold', 'value' => '6.5', 'school_id' => $this->a->id]);
        ObeConfig::flush();

        $this->assertSame(6.5, ObeConfig::forSchool($this->a->id, fn () => ObeConfig::cloPassThreshold()));
        $this->assertSame(5.0, ObeConfig::forSchool($this->b->id, fn () => ObeConfig::cloPassThreshold()));
        // Không đặt ngữ cảnh ⇒ chỉ đọc dòng mặc định (hành vi cũ).
        $this->assertSame(5.0, ObeConfig::cloPassThreshold());
    }

    public function test_ngu_canh_duoc_tra_lai_ke_ca_khi_co_loi(): void
    {
        SystemSetting::create(['key' => 'obe.clo_pass_threshold', 'value' => '5', 'school_id' => null]);
        SystemSetting::create(['key' => 'obe.clo_pass_threshold', 'value' => '9', 'school_id' => $this->a->id]);
        ObeConfig::flush();

        try {
            ObeConfig::forSchool($this->a->id, function () {
                throw new \RuntimeException('lỗi giữa chừng');
            });
        } catch (\RuntimeException) {
            // mong đợi
        }

        $this->assertNull(ObeConfig::schoolId(), 'Ngữ cảnh phải được trả lại dù có ngoại lệ');
        $this->assertSame(5.0, ObeConfig::cloPassThreshold());
    }

    public function test_luu_tham_so_rieng_qua_man_hinh_Truong(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->a->id)
            ->put(route('manager.schools.obe-settings', $this->a->uuid), [
                'settings' => [
                    'obe.clo_pass_threshold' => '6.5',
                    'obe.target_attainment_percent' => '75',
                    // Khoá ngoài danh sách cho phép — giá trị vẫn hợp lệ về kiểu để chắc chắn
                    // rằng nó bị loại vì KHOÁ, không phải vì rớt validate.
                    'app.name' => '123',
                ],
                'scale_weights' => ['A' => '4', 'X' => '9'],   // mức 'X' không hợp lệ
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('6.5', SystemSetting::where('school_id', $this->a->id)
            ->where('key', 'obe.clo_pass_threshold')->value('value'));
        $this->assertSame('4.00', MappingScaleWeight::where('school_id', $this->a->id)
            ->where('level', 'A')->value('weight_percent'));

        // Chỉ nhận đúng tham số đo — không mở cửa cho mọi khoá cấu hình.
        $this->assertDatabaseMissing('system_settings', ['key' => 'app.name']);
        $this->assertDatabaseMissing('mapping_scale_weights', ['level' => 'X']);

        ObeConfig::flush();
        $this->assertSame(6.5, ObeConfig::forSchool($this->a->id, fn () => ObeConfig::cloPassThreshold()));
        $this->assertSame(5.0, ObeConfig::forSchool($this->b->id, fn () => ObeConfig::cloPassThreshold()));
    }

    public function test_bo_trong_o_thi_quay_ve_dung_mac_dinh(): void
    {
        $admin = $this->superAdmin();
        SystemSetting::create(['key' => 'obe.clo_pass_threshold', 'value' => '9', 'school_id' => $this->a->id]);

        $this->actingAs($admin)
            ->withUnencryptedCookie('obe_working_school', (string) $this->a->id)
            ->put(route('manager.schools.obe-settings', $this->a->uuid), [
                'settings' => ['obe.clo_pass_threshold' => ''],
                'scale_weights' => [],
            ])
            ->assertRedirect();

        // Xoá dòng riêng, KHÔNG lưu giá trị 0.
        $this->assertDatabaseMissing('system_settings', [
            'school_id' => $this->a->id, 'key' => 'obe.clo_pass_threshold',
        ]);
        ObeConfig::flush();
        $this->assertSame(5.0, ObeConfig::forSchool($this->a->id, fn () => ObeConfig::cloPassThreshold()));
    }

    private function superAdmin(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web'],
        ));

        return $user;
    }

    public function test_muc_I_R_M_A_cung_tach_duoc_theo_truong(): void
    {
        foreach (['I' => 1, 'R' => 2, 'M' => 3, 'A' => 3] as $level => $w) {
            MappingScaleWeight::create(['level' => $level, 'weight_percent' => $w, 'school_id' => null]);
        }
        // Trường A muốn mức A nặng hơn (4 thay vì 3).
        MappingScaleWeight::create(['level' => 'A', 'weight_percent' => 4, 'school_id' => $this->a->id]);

        $this->assertSame('4.00', MappingScaleWeight::forSchool($this->a->id)['A']);
        $this->assertSame('3.00', MappingScaleWeight::forSchool($this->b->id)['A']);
        $this->assertSame('3.00', MappingScaleWeight::forSchool(null)['A']);

        // Các mức không khai riêng vẫn lấy mặc định.
        $this->assertSame('2.00', MappingScaleWeight::forSchool($this->a->id)['R']);
    }
}
