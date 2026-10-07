<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Khoá TRÙNG cho 3 bảng kết quả đo.
 *
 * Vì sao: engine ghi kết quả bằng `updateOrCreate` (SELECT rồi INSERT — không nguyên tử), trong khi
 * từ lúc `RecalculateObeJob` fan-out theo lớp và chạy `--scale worker-measurements=N`, NHIỀU worker
 * cùng ghi PI/PLO của CÙNG một sinh viên (mỗi lớp 1 job, SV học 16 môn ⇒ 16 job). Hai worker cùng
 * thấy "chưa có dòng" ⇒ cùng INSERT ⇒ 2 dòng cho cùng (student, pi).
 *
 * Hậu quả: `ObeAttainmentService` đếm `students_measured = count(rows)` nên dòng trùng làm PHỒNG cả
 * tử lẫn mẫu ⇒ % mức đạt và avg_score sai; `coursePiScoreFromCloResults` lấy TB điểm CLO nên CLO
 * trùng cũng kéo lệch điểm PI.
 *
 * ĐÃ TÁI HIỆN trên Postgres: 6 tiến trình cùng ghi 300 khoá của một sinh viên ⇒ **1670 dòng cho
 * 300 khoá, cả 300 khoá đều trùng**. Thêm UNIQUE rồi chạy lại cùng kịch bản ⇒ đúng 300 dòng.
 *
 * UNIQUE này CHÍNH LÀ thứ chặn: Laravel 13 `updateOrCreate` → `createOrFirst` vốn có bắt
 * `UniqueConstraintViolationException` rồi truy vấn lại — nhưng chỉ khi DB CÓ ràng buộc để mà vi
 * phạm. Không có UNIQUE thì không có va chạm nào để bắt, hai tiến trình cùng INSERT trót lọt.
 * (Engine nay ghi bằng `upsert` cho gọn theo lô, nhưng đó là chuyện hiệu năng, không phải thứ
 * bảo đảm tính duy nhất.)
 *
 * Giữ nguyên các index thường đã có: chúng thừa so với UNIQUE nhưng gỡ index trên DB thật rủi ro
 * hơn lợi (đã có tiền sử deploy gãy), chi phí chỉ là chút write amplification.
 */
return new class extends Migration
{
    /** bảng => [cột khoá], tên index unique */
    private array $targets = [
        'student_clo_results' => [['student_id', 'class_id', 'clo_id'], 'scr_student_class_clo_unique'],
        'student_pi_results' => [['student_id', 'pi_id'], 'spi_student_pi_unique'],
        'student_plo_results' => [['student_id', 'plo_id'], 'spl_student_plo_unique'],
    ];

    public function up(): void
    {
        foreach ($this->targets as $table => [$columns, $indexName]) {
            $this->dedupe($table, $columns);

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
                $blueprint->unique($columns, $indexName);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $table => [$columns, $indexName]) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropUnique($indexName);
            });
        }
    }

    /**
     * Xoá dòng trùng, GIỮ dòng `id` lớn nhất = bản ghi được ghi sau cùng.
     * Viết bằng SQL chạy được trên cả Postgres (prod) lẫn SQLite (test).
     */
    private function dedupe(string $table, array $columns): void
    {
        $group = implode(', ', $columns);

        DB::statement(
            "DELETE FROM {$table} WHERE id NOT IN (SELECT MAX(id) FROM {$table} GROUP BY {$group})"
        );
    }
};
