<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cờ độ mịn dữ liệu điểm của khóa:
 *   null / 'component' = hành vi cũ (điểm theo từng bài đánh giá; ≤2024 = legacy CLO-only).
 *   'total'           = trường CHỈ có điểm tổng học phần → vẫn đo lên PI/PLO cấp chương trình
 *                       (điểm tổng gán cho mọi CLO của môn, StandardMeasurementStrategy cuốn lên PI/PLO).
 * Nullable + không set = không đổi hành vi hiện tại (an toàn ngược).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cohorts', function (Blueprint $table) {
            $table->string('grade_granularity', 20)->nullable()->after('start_year');
        });
    }

    public function down(): void
    {
        Schema::table('cohorts', function (Blueprint $table) {
            $table->dropColumn('grade_granularity');
        });
    }
};
