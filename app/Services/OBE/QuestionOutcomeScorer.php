<?php

declare(strict_types=1);

namespace App\Services\OBE;

use App\Models\Assessment;
use App\Services\Import\ClassSheetReader;
use Illuminate\Support\Collection;

/**
 * ĐIỂM CHUẨN ĐẦU RA TỪ ĐIỂM CÂU HỎI — một chỗ cho CLO của lớp và PI của môn.
 *
 * Theo tài liệu WCML khách gửi (công thức (1)–(4) cho CLO, (7)–(8) cho PI trong môn):
 *   1. mỗi câu quy về thang 10: điểm đạt / điểm tối đa × 10
 *   2. trong một bài: TRUNG BÌNH các câu đo CLO (hoặc PI) đó
 *   3. trong một khối (chuyên cần · giữa kỳ · cuối kỳ): trung bình các bài của khối có đo
 *   4. gộp các khối CÓ đo, trọng số khối chuẩn hóa theo tổng của chúng — môn không có chuyên cần,
 *      hoặc chuyên cần không gắn CLO, chỉ đơn giản là khối đó vắng mặt
 *
 * PI trong môn dùng đúng các bước trên với tập câu của MỌI CLO nối vào PI đó — không lấy trung bình
 * điểm CLO (WCML ghi rõ: "không được dùng điểm CLO thay cho công thức (7)–(8)").
 *
 * Trọng số khối = tổng trọng số các bài của khối trong đề cương (khối Đánh giá của phiếu: 13,33 × 3 bài
 * giữa kỳ là khối 40). Chỉ dùng khi tổng trọng số MỌI bài của môn đúng 100%, như phiếu bắt buộc. Dữ liệu
 * nhập theo cách cũ không đạt điều kiện đó thì giữ quy tắc cũ — quá trình / cuối kỳ theo hệ số của trường,
 * mỗi bài Σ đạt / Σ tối đa — để số đã công bố không đổi.
 */
final class QuestionOutcomeScorer
{
    public const BLOCK_CC = 'chuyen_can';
    public const BLOCK_GK = 'giua_ky';
    public const BLOCK_CK = 'cuoi_ky';

    public static function blockOf(Assessment $assessment): string
    {
        if ($assessment->assessment_category === 'final') {
            return self::BLOCK_CK;
        }
        $slug = ClassSheetReader::slug($assessment->name);

        return (str_contains($slug, 'chuyen_can') || $slug === 'cc') ? self::BLOCK_CC : self::BLOCK_GK;
    }

    /**
     * @param Collection<int,Assessment> $assessments mọi bài của đề cương
     * @return array<string,float>|null khối → trọng số; null ⇒ đề cương không đủ trọng số, dùng quy tắc cũ
     */
    public static function blockWeights(Collection $assessments): ?array
    {
        if ($assessments->isEmpty()) {
            return null;
        }

        $weights = [self::BLOCK_CC => 0.0, self::BLOCK_GK => 0.0, self::BLOCK_CK => 0.0];
        $total = 0.0;
        foreach ($assessments as $assessment) {
            if ($assessment->weight_percent === null) {
                return null;
            }
            $w = (float) $assessment->weight_percent;
            $weights[self::blockOf($assessment)] += $w;
            $total += $w;
        }

        return abs($total - 100) <= 0.01 ? $weights : null;
    }

    /**
     * @param Collection<int,Assessment> $assessments bài của đề cương, đã nạp `rubric.criteria`
     * @param array<int,float> $scoreByCriteria rubric_criteria_id → điểm đạt, của MỘT sinh viên trong MỘT lớp
     * @param array<int,int> $cloIds một CLO, hoặc mọi CLO của môn nối vào một PI
     * @return float|null thang 10; null ⇒ không câu nào có điểm
     */
    public static function score(Collection $assessments, array $scoreByCriteria, array $cloIds): ?float
    {
        $wanted = array_flip(array_map('intval', $cloIds));
        $weights = self::blockWeights($assessments);

        $byBlock = [];
        $process = [];
        $final = [];
        foreach ($assessments as $assessment) {
            $scaled = [];
            $achieved = 0.0;
            $max = 0.0;
            foreach ($assessment->rubric?->criteria ?? [] as $criteria) {
                $id = (int) $criteria->id;
                if (! isset($wanted[(int) $criteria->clo_id]) || ! array_key_exists($id, $scoreByCriteria) || (float) $criteria->max_score <= 0) {
                    continue;
                }
                $scaled[] = $scoreByCriteria[$id] / (float) $criteria->max_score * 10;
                $achieved += $scoreByCriteria[$id];
                $max += (float) $criteria->max_score;
            }
            if ($scaled === []) {
                continue;
            }

            if ($weights !== null) {
                $byBlock[self::blockOf($assessment)][] = array_sum($scaled) / count($scaled);
            } elseif ($assessment->assessment_category === 'final') {
                $final[] = $achieved / $max * 10;
            } else {
                $process[] = $achieved / $max * 10;
            }
        }

        if ($weights === null) {
            return ObeScoreFormula::weightedFromCategories($process, $final);
        }
        if ($byBlock === []) {
            return null;
        }

        $num = 0.0;
        $den = 0.0;
        $averages = [];
        foreach ($byBlock as $block => $values) {
            $avg = array_sum($values) / count($values);
            $averages[] = $avg;
            $num += $weights[$block] * $avg;
            $den += $weights[$block];
        }

        return $den > 0 ? $num / $den : array_sum($averages) / count($averages);
    }
}
