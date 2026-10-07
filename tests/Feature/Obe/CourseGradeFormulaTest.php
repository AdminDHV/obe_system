<?php

declare(strict_types=1);

namespace Tests\Feature\Obe;

use App\Services\OBE\CourseGradeFormula;
use PHPUnit\Framework\TestCase;

/**
 * Khoá quy tắc CỤM của Trường: chuyên cần 10% · giữa kỳ 40% (cho CẢ CỤM) · cuối kỳ 50%.
 * Số dùng trong test lấy từ màn hình BUS509-2023 mà Trường chụp gửi lại.
 */
class CourseGradeFormulaTest extends TestCase
{
    /** @return array<int, array{weight: float, category: string, score: float|null}> */
    private function bus509(?float $gk1 = 7.9): array
    {
        return [
            ['weight' => 10.0, 'category' => 'process', 'score' => 9.0],   // Chuyên cần
            ['weight' => 13.33, 'category' => 'process', 'score' => $gk1], // GK1
            ['weight' => 13.33, 'category' => 'process', 'score' => 8.1],  // GK2
            ['weight' => 13.33, 'category' => 'process', 'score' => 8.2],  // GK3
            ['weight' => 50.0, 'category' => 'final', 'score' => 8.3],     // Cuối kỳ
        ];
    }

    /** Đủ bài: 3 bài × 13.33% cho ra đúng con số của "trung bình 3 bài rồi × 40%". */
    public function test_three_midterms_equal_averaging_the_block(): void
    {
        $viaWeights = CourseGradeFormula::fromAssessments($this->bus509());
        $viaAverage = 0.1 * 9.0 + 0.4 * ((7.9 + 8.1 + 8.2) / 3) + 0.5 * 8.3;

        $this->assertEqualsWithDelta($viaAverage, $viaWeights, 0.005);
        $this->assertSame(8.28, round((float) $viaWeights, 2));
    }

    /**
     * Thiếu 1 bài giữa kỳ: cụm giữa kỳ VẪN 40%, chỉ trung bình 2 bài còn lại.
     * Cách cũ (chuẩn hoá toàn bảng) đẩy 13.33% sang cuối kỳ và ra số khác.
     */
    public function test_a_missing_midterm_stays_inside_the_midterm_block(): void
    {
        $v = CourseGradeFormula::fromAssessments($this->bus509(gk1: null));

        $this->assertEqualsWithDelta(0.1 * 9.0 + 0.4 * ((8.1 + 8.2) / 2) + 0.5 * 8.3, $v, 0.005);

        // Chốt chặn cách cũ: Σ(điểm×trọng_số)/Σ(trọng_số) trên các bài có điểm.
        $cu = (10 * 9.0 + 13.33 * 8.1 + 13.33 * 8.2 + 50 * 8.3) / (10 + 13.33 + 13.33 + 50);
        $this->assertNotEqualsWithDelta($cu, $v, 0.005);
    }

    /** Chưa thi cuối kỳ ⇒ bỏ hẳn cụm đó, KHÔNG tính như 0 điểm. */
    public function test_an_entirely_ungraded_block_is_dropped_not_zeroed(): void
    {
        $items = $this->bus509();
        $items[4]['score'] = null;

        $v = CourseGradeFormula::fromAssessments($items);

        $this->assertEqualsWithDelta(
            (10 * 9.0 + 39.99 * ((7.9 + 8.1 + 8.2) / 3)) / (10 + 39.99),
            $v,
            0.005,
        );
        $this->assertGreaterThan(8.0, $v);
    }

    /**
     * Trường khai khung trước, để giảng viên điền trọng số sau. Trong lúc chưa điền, môn vẫn phải
     * ra được điểm — trước đây bài trọng số 0 bị bỏ qua nên môn toàn bài trống KHÔNG có điểm.
     */
    public function test_blank_weights_share_the_remaining_percentage(): void
    {
        // Chưa khai gì cả ⇒ mỗi bài 100/3 ⇒ đúng bằng trung bình cộng.
        $trongHet = [
            ['weight' => 0, 'category' => 'process', 'score' => 6.0],
            ['weight' => 0, 'category' => 'process', 'score' => 9.0],
            ['weight' => 0, 'category' => 'final', 'score' => 8.0],
        ];
        $this->assertEqualsWithDelta(
            (6.0 + 9.0 + 8.0) / 3,
            CourseGradeFormula::fromAssessments($trongHet),
            0.005,
        );

        // Khai một phần: cuối kỳ 50%, hai bài giữa kỳ bỏ trống ⇒ mỗi bài 25%.
        $motPhan = [
            ['weight' => 0, 'category' => 'process', 'score' => 6.0],
            ['weight' => 0, 'category' => 'process', 'score' => 8.0],
            ['weight' => 50, 'category' => 'final', 'score' => 9.0],
        ];
        $this->assertEqualsWithDelta(
            0.25 * 6.0 + 0.25 * 8.0 + 0.5 * 9.0,
            CourseGradeFormula::fromAssessments($motPhan),
            0.005,
        );

        // Đã khai đủ 100% ⇒ không còn dư, bài trống không tham gia.
        $daDu = [
            ['weight' => 40, 'category' => 'process', 'score' => 6.0],
            ['weight' => 60, 'category' => 'final', 'score' => 9.0],
            ['weight' => 0, 'category' => 'process', 'score' => 1.0],
        ];
        $this->assertEqualsWithDelta(
            0.4 * 6.0 + 0.6 * 9.0,
            CourseGradeFormula::fromAssessments($daDu),
            0.005,
        );
    }

    /**
     * Trọng số do TRƯỜNG tự quyết — hệ thống KHÔNG ép chia đều.
     *
     * Khách nói rõ: "để người nhập điểm họ chọn — 1 bài thì 40%, 2 bài có thể bằng nhau, cũng có
     * thể chênh nhau như 30 và 10". Việc gộp cụm theo (loại, trọng số) chỉ để hiển thị và để bù
     * khi thiếu bài; nó KHÔNG được biến trọng số chênh nhau thành trung bình.
     */
    public function test_trong_so_chenh_nhau_duoc_giu_nguyen_khong_bi_ep_trung_binh(): void
    {
        $chenh = [
            ['weight' => 10, 'category' => 'process', 'score' => 9.0],  // chuyên cần
            ['weight' => 30, 'category' => 'process', 'score' => 6.0],  // giữa kỳ 1
            ['weight' => 10, 'category' => 'process', 'score' => 8.0],  // giữa kỳ 2
            ['weight' => 50, 'category' => 'final', 'score' => 7.0],
        ];
        $this->assertEqualsWithDelta(
            0.1 * 9.0 + 0.3 * 6.0 + 0.1 * 8.0 + 0.5 * 7.0,
            CourseGradeFormula::fromAssessments($chenh),
            0.0005,
        );

        // Nếu bị ép chia đều (cụm giữa kỳ 40% ÷ 2) thì hai bài thành 20+20 và ra số KHÁC.
        $this->assertNotEqualsWithDelta(
            0.1 * 9.0 + 0.2 * 6.0 + 0.2 * 8.0 + 0.5 * 7.0,
            CourseGradeFormula::fromAssessments($chenh),
            0.005,
        );

        // Một bài duy nhất mang trọn 40% cũng phải đúng.
        $motBai = [
            ['weight' => 10, 'category' => 'process', 'score' => 9.0],
            ['weight' => 40, 'category' => 'process', 'score' => 6.0],
            ['weight' => 50, 'category' => 'final', 'score' => 7.0],
        ];
        $this->assertEqualsWithDelta(
            0.1 * 9.0 + 0.4 * 6.0 + 0.5 * 7.0,
            CourseGradeFormula::fromAssessments($motBai),
            0.0005,
        );
    }

    public function test_no_scores_at_all_returns_null(): void
    {
        $this->assertNull(CourseGradeFormula::fromAssessments([
            ['weight' => 40.0, 'category' => 'process', 'score' => null],
            ['weight' => 60.0, 'category' => 'final', 'score' => null],
        ]));
    }
}
