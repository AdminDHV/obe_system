<?php

declare(strict_types=1);

namespace Tests\Unit\Obe;

use App\Services\OBE\WeightedAttainmentFormula;
use PHPUnit\Framework\TestCase;

/**
 * Bám đúng ví dụ số trong file công thức của khách
 * (docs/cach_tinh_CLO_PI_PLO.md / "New Đo lường … chương trình đào tạo.xlsx").
 */
class WeightedAttainmentFormulaTest extends TestCase
{
    public function test_pi_ratio_matches_client_example(): void
    {
        // [credit, mức(I/R/M/A→1/2/3), điểm_PI_môn(0..10)]
        // PI1: Môn1(2TC,R=2,7.5), Môn3(3TC,R=2,6.6667), Môn4(3TC,M=3,5.0)
        $pi1 = WeightedAttainmentFormula::piRatio([[2, 2, 7.5], [3, 2, 20 / 3], [3, 3, 5.0]]);
        $this->assertEqualsWithDelta(0.6053, $pi1, 0.0005);

        // PI2: Môn1(2,R=2,6.6667), Môn2(2,I=1,7.5), Môn3(3,M=3,8.3333)
        $pi2 = WeightedAttainmentFormula::piRatio([[2, 2, 20 / 3], [2, 1, 7.5], [3, 3, 25 / 3]]);
        $this->assertEqualsWithDelta(0.7778, $pi2, 0.0005);

        // PI3: Môn2(2,R=2,6.6667), Môn4(3,M=3,7.5)
        $pi3 = WeightedAttainmentFormula::piRatio([[2, 2, 20 / 3], [3, 3, 7.5]]);
        $this->assertEqualsWithDelta(0.7244, $pi3, 0.0005);

        // PLO1 = Σ(PI_ratio × trọng_số_PI), trọng số 0.3/0.3/0.4 → 0.70466
        $plo1 = WeightedAttainmentFormula::ploRatio([[$pi1, 0.3], [$pi2, 0.3], [$pi3, 0.4]]);
        $this->assertEqualsWithDelta(0.7047, $plo1, 0.0005);
    }

    public function test_plo_ratio_equal_weights_is_simple_average(): void
    {
        $plo = WeightedAttainmentFormula::ploRatio([[0.6, null], [0.8, null]]);
        $this->assertEqualsWithDelta(0.7, $plo, 0.00001);
    }

    public function test_empty_contributions_return_null(): void
    {
        $this->assertNull(WeightedAttainmentFormula::piRatio([]));
        $this->assertNull(WeightedAttainmentFormula::ploRatio([]));
    }
}
