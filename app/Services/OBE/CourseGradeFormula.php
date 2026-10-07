<?php

declare(strict_types=1);

namespace App\Services\OBE;

/**
 * Điểm tổng kết môn của 1 sinh viên, từ các bài đánh giá ĐÃ CÓ ĐIỂM.
 *
 * Quy tắc của Trường (file `QTKD_Diem_PI_PLO_HocVien_2023`, sheet `1_Diem_HocPhan`):
 * chuyên cần 10% · giữa kỳ 40% · cuối kỳ 50%, trong đó **cụm giữa kỳ là 40% cho cả cụm** —
 * môn có n bài giữa kỳ thì lấy TRUNG BÌNH n bài rồi mới nhân 40%.
 *
 * Điểm mấu chốt: THIẾU một bài thì trọng số của bài đó chia lại **trong cùng cụm**, không chia
 * lại toàn bảng. Thiếu GK1 ⇒ trung bình 2 bài giữa kỳ còn lại, cụm vẫn đúng 40%; cuối kỳ KHÔNG
 * được phồng lên. Cách cũ (`Σ điểm×trọng_số / Σ trọng_số` trên toàn bộ bài có điểm) đẩy phần
 * 13.33% bị thiếu sang cho cuối kỳ, làm cụm giữa kỳ tụt còn 26.7%:
 *
 *   chuyên cần 9 · GK2 8 · GK3 8 · cuối kỳ 6 · thiếu GK1
 *     cách cũ  → 6.96      (cuối kỳ nặng lên)
 *     theo file → 8.15 × 0.4 + … = 7.10
 *
 * Cả cụm không có điểm nào thì bỏ cụm đó ra, phần còn lại tự chuẩn hoá — nếu không, sinh viên
 * chưa thi cuối kỳ sẽ bị tính như thi 0 điểm.
 *
 * Cụm được nhận diện bằng (loại bài, trọng số): GK1/GK2/GK3 cùng là `process` và cùng 13.33 nên
 * vào chung một cụm, còn chuyên cần (10) và cuối kỳ (50) đứng riêng. Không dùng tên bài vì tên do
 * trường tự đặt. Hai bài khác nhau vô tình trùng trọng số thì bị gộp — nhưng gộp vẫn giữ đúng
 * tổng trọng số của chúng, tức vẫn đúng hơn cách cũ.
 */
class CourseGradeFormula
{
    /**
     * Trọng số THỰC DÙNG của từng bài, sau khi bù cho những bài BỎ TRỐNG trọng số.
     *
     * Trường muốn khai khung trước rồi để giảng viên điền trọng số sau. Trong lúc chưa điền,
     * môn vẫn phải ra được điểm — nếu bỏ qua bài trống thì môn mà mọi bài đều trống sẽ KHÔNG có
     * điểm tổng kết, tức khung khai xong mà bảng điểm trắng trơn.
     *
     * Quy tắc: bài trống chia đều phần trăm CÒN LẠI (100 − tổng đã khai).
     *   - Chưa khai gì cả  → tổng đã khai = 0 ⇒ mỗi bài được 100/n, tức TRUNG BÌNH CỘNG,
     *     đúng "phương án trung bình" trường đang dùng cho bản nháp.
     *   - Khai một phần    → bài trống chia nhau phần dư. Ví dụ cuối kỳ 50%, ba bài giữa kỳ
     *     bỏ trống ⇒ mỗi bài 50/3.
     *   - Đã khai đủ 100%  → không còn dư, bài trống không tham gia (đúng, vì phần trăm đã đủ).
     *
     * ⚠️ `StudentProgressService::courseGrades()` lặp lại đúng quy tắc này bằng SQL (chạy cho cả
     * ngành nên không kéo từng dòng điểm về PHP được). Sửa ở đây thì phải sửa cả bên đó —
     * `CourseGradeFormulaTest` khoá hợp đồng để hai bên không trôi ra khỏi nhau.
     *
     * @param  array<int, array{weight: float, category: string, score: float|null}>  $assessments
     * @return array<int, array{weight: float, category: string, score: float|null}>
     */
    public static function resolveWeights(array $assessments): array
    {
        $daKhai = 0.0;
        $soTrong = 0;

        foreach ($assessments as $item) {
            $w = (float) $item['weight'];
            if ($w > 0.0) {
                $daKhai += $w;
            } else {
                $soTrong++;
            }
        }

        if ($soTrong === 0) {
            return $assessments;
        }

        $duMoiBai = max(100.0 - $daKhai, 0.0) / $soTrong;

        foreach ($assessments as $i => $item) {
            if ((float) $item['weight'] <= 0.0) {
                $assessments[$i]['weight'] = $duMoiBai;
            }
        }

        return $assessments;
    }

    /**
     * @param  array<int, array{weight: float, category: string, score: float|null}>  $assessments
     *         MỌI bài đánh giá của đề cương, kể cả bài sinh viên chưa có điểm (score = null) —
     *         thiếu chúng thì không biết cụm gốc nặng bao nhiêu.
     */
    public static function fromAssessments(array $assessments): ?float
    {
        /** @var array<string, array{weight: float, scores: array<int, float>}> $groups */
        $groups = [];

        foreach (self::resolveWeights($assessments) as $item) {
            $weight = (float) $item['weight'];
            if ($weight <= 0.0) {
                continue;
            }

            $key = $item['category'] . '|' . number_format($weight, 2, '.', '');
            $groups[$key]['weight'] = ($groups[$key]['weight'] ?? 0.0) + $weight;

            if ($item['score'] !== null) {
                $groups[$key]['scores'][] = (float) $item['score'];
            }
        }

        $num = 0.0;
        $den = 0.0;

        foreach ($groups as $group) {
            $scores = $group['scores'] ?? [];
            if ($scores === []) {
                continue; // cả cụm chưa có điểm → không tính, cũng không kéo điểm xuống
            }

            $num += $group['weight'] * (array_sum($scores) / count($scores));
            $den += $group['weight'];
        }

        return $den > 0.0 ? $num / $den : null;
    }
}
