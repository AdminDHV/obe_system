<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\School;
use App\Models\SystemSetting;
use App\Services\Demo\DemoDataService;
use App\Services\Demo\DemoSchools;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\ObeConfig;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cột "Mức" trong bảng mức đạt phải tự nói ra nghĩa của nó.
 *
 * Trước đây bảng chỉ in con số trần ("3" trong ô xám) và không đâu chú thích. Nhãn ghép ở server
 * chứ không ở giao diện, vì ngưỡng khai theo từng trường — giao diện tự đoán thì trường nào đổi
 * ngưỡng là nhãn nói sai.
 */
class AttainmentLevelLabelTest extends TestCase
{
    use RefreshDatabase;

    private function ngành(): array
    {
        app(DemoDataService::class)->seed();
        $school = School::where('code', DemoSchools::all()[0]['code'])->first();
        $program = Program::where('school_id', $school->id)->first();

        return [$school, $program];
    }

    public function test_nhan_di_kem_moi_hang(): void
    {
        [$school, $program] = $this->ngành();

        $kq = ObeConfig::forSchool($school->id, fn () => app(ObeAttainmentService::class)->ploAttainment($program->id, null));

        foreach ($kq['plos'] as $r) {
            $this->assertNotNull($r['level_label'], "PLO {$r['plo_code']} thiếu nhãn mức");
            $this->assertStringContainsString('Mức ' . $r['level'], $r['level_label']);
            $this->assertMatchesRegularExpression('/\d+%/', $r['level_label'], 'Nhãn phải nêu ngưỡng, không chỉ số bậc');
        }
    }

    public function test_nhan_chay_theo_nguong_cua_truong_chu_khong_in_cung(): void
    {
        [$school, $program] = $this->ngành();

        // Trường khai ngưỡng riêng: 90 / 50.
        SystemSetting::updateOrCreate(
            ['key' => 'obe.attainment_level_thresholds', 'school_id' => $school->id],
            ['value' => '90,50'],
        );
        ObeConfig::flush();

        $kq = ObeConfig::forSchool($school->id, fn () => app(ObeAttainmentService::class)->ploAttainment($program->id, null));
        $nhan = array_filter(array_column($kq['plos'], 'level_label'));

        $this->assertNotEmpty($nhan);
        foreach ($nhan as $n) {
            $this->assertMatchesRegularExpression('/(90|50)%/', $n, "Nhãn '{$n}' không theo ngưỡng trường đã khai");
        }
        // Bậc cao nhất giờ là 2 (hai ngưỡng), không còn 3 như mặc định.
        $this->assertLessThanOrEqual(2, max(array_column($kq['plos'], 'level')));
    }
}
