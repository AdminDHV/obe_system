<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BẬC ĐÀO TẠO của ngành, SỐ TIẾT và BỘ MÔN PHỤ TRÁCH của học phần.
 *
 * Bậc quyết định ngưỡng đạt theo quy chế: đại học qua môn 4,0 (Thông tư 08/2021), thạc sĩ 5,5
 * (Thông tư 23/2021) — xem `ObeConfig::LEVEL_DEFAULTS`. Chưa khai bậc ⇒ giữ nguyên ngưỡng đang dùng,
 * nên dữ liệu cũ không đổi số.
 *
 * Số tiết và bộ môn phụ trách là hai mục bắt buộc trong mẫu "Báo cáo học phần" của trường, hệ thống
 * chưa lưu nên trước đây phải gõ tay mỗi lần xuất báo cáo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('level', 20)->nullable()->after('name');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedSmallInteger('periods')->nullable()->after('credits');
            $table->string('managing_unit', 150)->nullable()->after('periods');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('level');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['periods', 'managing_unit']);
        });
    }
};
