<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Models\SystemSetting;
use App\Services\OBE\ObeConfig;
use App\Services\OBE\ObeScoreFormula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the shared process/final formula reproduces the worked examples in
 * docs/cach_tinh_CLO_PI_PLO.md (Model A: PI uses
 * the same formula as CLO). system_settings is empty → ObeConfig defaults 0.4/0.6.
 */
class ObeFormulaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ObeConfig::flush();
    }

    private function round2(?float $v): ?float
    {
        return $v === null ? null : round($v, 2);
    }

    /**
     * Trường chấm 10% chuyên cần + 40% giữa kỳ + 50% cuối kỳ. Chuyên cần KHÔNG đo được bằng câu
     * hỏi, nên phần đo lường chỉ còn 40:50 và phải chuẩn hoá lại trên 90 — tức 4/9 và 5/9.
     *
     * Nếu không chuẩn hoá, 0.4×GK + 0.5×CK cho ra một con số nhỏ hơn cả hai (mất 10%), vô nghĩa
     * trên thang 10. Đối chiếu 42 học viên × 24 PI trong file của Trường: hệ số giữa kỳ 4/9 khớp
     * 692/714 ô (lệch tối đa 0.006 — làm tròn trong file gốc), trong khi 0.4 chỉ khớp 341.
     */
    public function test_weights_are_renormalised_so_a_school_may_enter_raw_percentages(): void
    {
        SystemSetting::updateOrCreate(['key' => 'obe.process_weight', 'school_id' => null], ['value' => '0.4']);
        SystemSetting::updateOrCreate(['key' => 'obe.final_weight', 'school_id' => null], ['value' => '0.5']);
        ObeConfig::flush();

        // GK 8.0, CK 9.0 → 8×4/9 + 9×5/9 = 8.5555…
        $this->assertEqualsWithDelta(
            8.0 * 4 / 9 + 9.0 * 5 / 9,
            ObeScoreFormula::weightedFromCategories([8.0], [9.0]),
            0.0001,
        );

        // Kết quả luôn nằm giữa hai đầu vào — chốt chặn cho lỗi "mất 10%".
        $v = ObeScoreFormula::weightedFromCategories([8.0], [9.0]);
        $this->assertGreaterThan(8.0, $v);
        $this->assertLessThan(9.0, $v);
    }

    /** Hệ số mặc định 0.4/0.6 có tổng bằng 1 nên việc chuẩn hoá không được làm đổi số cũ. */
    public function test_default_weights_are_unchanged_by_normalisation(): void
    {
        $this->assertSame(8.50, $this->round2(ObeScoreFormula::weightedFromCategories([10.0], [7.5])));
    }

    public function test_clo_examples_from_doc(): void
    {
        // CLO1: process-only (10.0, 6.66) → simple average, NO coefficient
        $this->assertSame(8.33, $this->round2(ObeScoreFormula::weightedFromCategories([10.0, 6.66], [])));
        // CLO2: process(10.0) + final(7.5) → 10*0.4 + 7.5*0.6
        $this->assertSame(8.50, $this->round2(ObeScoreFormula::weightedFromCategories([10.0], [7.5])));
        // CLO3: process(8.0,10.0) avg=9.0 *0.4 + final(6.66)*0.6 = 3.6 + 3.996 = 7.596
        // (Doc viết 7.59 do làm tròn trung gian 3.996→3.99; giữ full precision đúng = 7.60.)
        $this->assertSame(7.60, $this->round2(ObeScoreFormula::weightedFromCategories([8.0, 10.0], [6.66])));
        // CLO4: single process value → itself
        $this->assertSame(8.75, $this->round2(ObeScoreFormula::weightedFromCategories([8.75], [])));
    }

    public function test_pi_examples_from_doc(): void
    {
        // PI1.1: single column → raw value
        $this->assertSame(10.0, $this->round2(ObeScoreFormula::weightedFromCategories([10.0], [])));
        // PI4.2: process(8.25)+final(8.33) → 8.25*0.4 + 8.33*0.6 = 8.298
        $this->assertSame(8.30, $this->round2(ObeScoreFormula::weightedFromCategories([8.25], [8.33])));
        // PI6.2: process(6.66, 8.0) only → average = 7.33
        $this->assertSame(7.33, $this->round2(ObeScoreFormula::weightedFromCategories([6.66, 8.0], [])));
        // PI8.2: process(9.33)+final(6.66) → 9.33*0.4 + 6.66*0.6 = 7.728
        $this->assertSame(7.73, $this->round2(ObeScoreFormula::weightedFromCategories([9.33], [6.66])));
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull(ObeScoreFormula::weightedFromCategories([], []));
    }

    public function test_final_only_is_average(): void
    {
        $this->assertSame(7.0, $this->round2(ObeScoreFormula::weightedFromCategories([], [6.0, 8.0])));
    }
}
