<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tham số đo được phép KHÁC NHAU GIỮA CÁC TRƯỜNG (tài liệu §C.1 đã dự tính từ đầu).
 *
 * Trước đây `system_settings` (mọi ngưỡng: đạt CLO, đạt PLO, % tích lũy tối thiểu…) và
 * `mapping_scale_weights` (mức I/R/M/A) đều DÙNG CHUNG TOÀN HỆ THỐNG — trường A chỉnh là mọi
 * trường đổi theo. Với sản phẩm bán cho nhiều trường thì đó là lỗi.
 *
 * Cách làm: thêm `school_id` NULLABLE.
 *   - `school_id = NULL` → dòng MẶC ĐỊNH dùng chung (chính là dữ liệu đang có).
 *   - có `school_id`     → dòng riêng của trường, ĐÈ lên mặc định.
 * Không khai riêng ⇒ hành vi y hệt hôm nay, nên di trú không đổi bất kỳ con số nào.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')
                ->constrained('schools')->cascadeOnDelete();
            $table->dropUnique('system_settings_key_unique');
            $table->unique(['school_id', 'key'], 'system_settings_school_key_unique');
        });

        Schema::table('mapping_scale_weights', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')
                ->constrained('schools')->cascadeOnDelete();
            $table->dropUnique('mapping_scale_weights_level_unique');
            $table->unique(['school_id', 'level'], 'mapping_scale_weights_school_level_unique');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropUnique('system_settings_school_key_unique');
            $table->dropConstrainedForeignId('school_id');
            $table->unique('key', 'system_settings_key_unique');
        });

        Schema::table('mapping_scale_weights', function (Blueprint $table) {
            $table->dropUnique('mapping_scale_weights_school_level_unique');
            $table->dropConstrainedForeignId('school_id');
            $table->unique('level', 'mapping_scale_weights_level_unique');
        });
    }
};
