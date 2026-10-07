<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TRỌNG SỐ PI THEO KHÓA — lỗ cuối cùng của việc "khóa mới làm đổi số khóa cũ".
 *
 * `pis.weight` nằm ở cấp NGÀNH, mà nạp khung là `updateOrCreate(['plo_id','code'])` ⇒ khai lại
 * trọng số cho khóa 2025 đè lên đúng dòng khóa 2023 đang dùng. Đã chứng minh: PI1.1 0.4 → 0.9
 * làm PLO1 của một học viên khóa 2023 nhảy 0.8191 → 0.8394.
 *
 * Đóng băng đề cương theo khóa (`course_syllabus.version`) cô lập được CLO và ma trận CLO→PI,
 * nhưng KHÔNG chạm tới trọng số PI — nên cần bảng đè riêng này.
 *
 * Cố ý KHÔNG thêm `cohort_id` vào `pis`: PI là danh mục của ngành (mã, mô tả, thuộc PLO nào) và
 * dùng chung là đúng; chỉ có TRỌNG SỐ mới là tham số đo cần đóng băng theo khóa.
 * Không khai ⇒ engine dùng `pis.weight` như cũ (tương thích ngược).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohort_pi_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->foreignId('pi_id')->constrained('pis')->cascadeOnDelete();
            $table->decimal('weight', 8, 4);
            $table->timestamps();

            $table->unique(['cohort_id', 'pi_id'], 'cohort_pi_weights_unique');
        });

        // ĐÓNG BĂNG hiện trạng: mọi khóa đang có đều được chốt trọng số PI hiện tại của ngành.
        // Không có bước này thì khóa cũ (chưa có dòng đè) vẫn bị khung khóa mới làm đổi số.
        DB::statement("
            INSERT INTO cohort_pi_weights (cohort_id, pi_id, weight, created_at, updated_at)
            SELECT c.id, p.id, p.weight, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM cohorts c
            JOIN plos pl ON pl.program_id = c.program_id
            JOIN pis p ON p.plo_id = pl.id
            WHERE p.weight IS NOT NULL
              AND p.deleted_at IS NULL
              AND pl.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('cohort_pi_weights');
    }
};
