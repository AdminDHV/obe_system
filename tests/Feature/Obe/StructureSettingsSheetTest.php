<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\MappingScaleWeight;
use App\Models\School;
use App\Models\SystemSetting;
use App\Services\OBE\ObeConfig;
use App\Services\OBE\StructureBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Sheet `ThamSo` trong file khung: trường khai luôn hệ số và ngưỡng của mình.
 *
 * Trước đây khung khai được toàn bộ cấu trúc nhưng tham số đo thì phải vào màn hình Trường gõ
 * tay. Quên bước đó thì hệ thống LẶNG LẼ chạy bằng mặc định — số vẫn hiện, vẫn trông hợp lý, chỉ
 * là sai. Đúng lỗi đó đã kéo độ khớp với file của một trường từ 714/714 xuống 475/714.
 */
class StructureSettingsSheetTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        ObeConfig::flush();
        $this->school = School::create(['code' => 'S1', 'name' => 'Trường 1']);
    }

    private function nap(array $rows): array
    {
        return app(StructureBuilderService::class)->build(
            ['ThamSo' => new Collection(array_map(
                fn ($r) => ['tham_so' => $r[0], 'gia_tri' => $r[1]],
                $rows,
            ))],
            $this->school->id,
        );
    }

    public function test_khai_he_so_va_muc_tu_file_khung(): void
    {
        $kq = $this->nap([
            ['obe.process_weight', 0.4],
            ['obe.final_weight', 0.5],
            ['obe.clo_pass_threshold', 5],
            ['muc.A', 4],
        ]);

        $this->assertSame([], $kq['errors']);

        ObeConfig::forSchool($this->school->id, function () {
            $this->assertEqualsWithDelta(0.4, ObeConfig::processWeight(), 0.0001);
            $this->assertEqualsWithDelta(0.5, ObeConfig::finalWeight(), 0.0001);
            $this->assertEqualsWithDelta(5.0, ObeConfig::cloPassThreshold(), 0.0001);
        });

        // Mức A riêng của trường này = 4, các mức khác vẫn theo mặc định chung.
        $this->assertEqualsWithDelta(
            4.0,
            (float) MappingScaleWeight::forSchool($this->school->id)['A'],
            0.0001,
        );
    }

    /** Trường KHÁC không bị ảnh hưởng — đây là điểm sống còn của sản phẩm bán cho nhiều trường. */
    public function test_khong_lam_anh_huong_truong_khac(): void
    {
        $khac = School::create(['code' => 'S2', 'name' => 'Trường 2']);

        $this->nap([['obe.process_weight', 0.4], ['obe.final_weight', 0.5]]);

        ObeConfig::forSchool($khac->id, function () {
            $this->assertEqualsWithDelta(0.4, ObeConfig::processWeight(), 0.0001);
            $this->assertEqualsWithDelta(0.6, ObeConfig::finalWeight(), 0.0001, 'Trường khác phải giữ mặc định 0.6');
        });
    }

    /** Ô để TRỐNG ⇒ quay về mặc định, để tải lại file vừa thêm vừa bỏ được. */
    public function test_de_trong_thi_quay_ve_mac_dinh(): void
    {
        $this->nap([['obe.final_weight', 0.5]]);
        $this->nap([['obe.final_weight', '']]);

        $this->assertDatabaseMissing('system_settings', [
            'school_id' => $this->school->id, 'key' => 'obe.final_weight',
        ]);

        ObeConfig::forSchool($this->school->id, function () {
            $this->assertEqualsWithDelta(0.6, ObeConfig::finalWeight(), 0.0001);
        });
    }

    /**
     * File Excel do người ngoài tải lên, nên KHÔNG được mở cửa cho mọi khoá cấu hình hệ thống —
     * chỉ đúng những tham số đo có trên màn hình Trường.
     */
    public function test_chan_khoa_cau_hinh_ngoai_danh_sach_trang(): void
    {
        $kq = $this->nap([
            ['app.debug', 'true'],
            ['obe.khong_ton_tai', 1],
            ['muc.X', 9],
            ['obe.process_weight', 'không phải số'],
        ]);

        $this->assertCount(4, $kq['errors'], 'Cả 4 dòng đều phải bị từ chối');
        $this->assertDatabaseMissing('system_settings', ['key' => 'app.debug']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'obe.khong_ton_tai']);
        $this->assertDatabaseMissing('mapping_scale_weights', ['level' => 'X']);
    }
}
