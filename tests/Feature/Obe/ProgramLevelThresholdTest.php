<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\Program;
use App\Models\School;
use App\Models\SystemSetting;
use App\Services\OBE\ObeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NGƯỠNG ĐẠT THEO BẬC ĐÀO TẠO — quy chế đặt mức khác nhau cho đại học và thạc sĩ:
 * qua môn 4,0 (Thông tư 08/2021) và 5,5 (Thông tư 23/2021). Ngành chưa khai bậc thì giữ nguyên
 * ngưỡng cũ để dữ liệu đã đo không đổi số; trường khai riêng thì bản khai của trường thắng.
 */
class ProgramLevelThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ObeConfig::flush();
    }

    private function nguong(?string $level): array
    {
        return ObeConfig::forProgram(null, $level, fn () => [
            'clo' => ObeConfig::cloPassThreshold(),
            'mon' => ObeConfig::coursePassThreshold(),
            'plo' => ObeConfig::ploAttainmentRatioPass(),
            'muc' => ObeConfig::ploLevelThresholds(),
        ]);
    }

    public function test_dai_hoc_va_thac_si_co_nguong_rieng(): void
    {
        $dh = $this->nguong(Program::LEVEL_DAI_HOC);
        $this->assertSame(4.0, $dh['clo']);
        $this->assertSame(4.0, $dh['mon']);
        $this->assertSame(0.4, $dh['plo']);
        $this->assertSame([8.0, 6.0, 4.0, 2.0], $dh['muc']);

        $ths = $this->nguong(Program::LEVEL_THAC_SI);
        $this->assertSame(5.5, $ths['clo']);
        $this->assertSame(5.5, $ths['mon']);
        $this->assertSame(0.55, $ths['plo']);
        $this->assertSame([8.5, 7.0, 5.5, 2.75], $ths['muc']);
    }

    public function test_chua_khai_bac_thi_giu_nguyen_nguong_cu(): void
    {
        $cu = $this->nguong(null);

        $this->assertSame(5.0, $cu['clo']);
        $this->assertSame(5.5, $cu['mon']);
        $this->assertSame(0.5, $cu['plo']);
    }

    public function test_tham_so_khai_rieng_cua_truong_thang_mac_dinh_cua_bac(): void
    {
        $school = School::create(['code' => 'BAC', 'name' => 'Trường thử bậc', 'is_active' => true]);
        SystemSetting::create(['school_id' => $school->id, 'key' => 'obe.clo_pass_threshold', 'value' => '6']);
        ObeConfig::flush();

        $clo = ObeConfig::forProgram($school->id, Program::LEVEL_DAI_HOC, fn () => ObeConfig::cloPassThreshold());

        $this->assertSame(6.0, $clo, 'Trường đã khai riêng thì lấy theo trường, không lấy mặc định của bậc');
    }

    public function test_file_khai_bao_ghi_duoc_bac_so_tiet_va_bo_mon(): void
    {
        $school = School::create(['code' => 'BAC2', 'name' => 'Trường thử khai báo', 'is_active' => true]);

        $kq = app(\App\Services\OBE\StructureBuilderService::class)->build(
            array_map(fn ($rows) => new \Illuminate\Support\Collection($rows), [
                'Nganh_Khoa' => [['ma_nganh' => 'QTKD', 'ten_nganh' => 'Quản trị kinh doanh', 'bac' => 'Đại học', 'ten_khoa' => 'Khóa 2026', 'nam_bat_dau' => 2026]],
                'MonHoc' => [['ma_mon' => '10122', 'ten_mon' => 'Kiểm soát', 'tin_chi' => 3, 'so_tiet' => 50, 'bo_mon' => 'Bộ môn Quản trị Kinh doanh']],
            ]),
            $school->id,
        );

        $this->assertSame([], $kq['errors']);
        $this->assertSame(Program::LEVEL_DAI_HOC, Program::where('code', 'QTKD')->value('level'));

        $mon = \App\Models\Course::where('code', '10122')->firstOrFail();
        $this->assertSame(50, (int) $mon->periods);
        $this->assertSame('Bộ môn Quản trị Kinh doanh', $mon->managing_unit);
    }
}
