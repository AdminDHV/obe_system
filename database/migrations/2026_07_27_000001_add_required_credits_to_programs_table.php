<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Số tín chỉ TÍCH LŨY mà chương trình yêu cầu để tốt nghiệp (vd thạc sĩ QTKD = 60).
 *
 * Dùng làm MẪU SỐ cho "tiến độ tích lũy" của từng SV, từ đó biết SV nào đã học đủ để
 * kết quả đo PLO của họ mang tính đại diện. Trước đây hệ thống xuất PI/PLO đầy đủ cho
 * cả SV mới học 11/16 học phần — nhìn y hệt SV học đủ, làm sai lệch dòng "% đạt" của khóa.
 *
 * NULL = chưa khai ⇒ KHÔNG lọc ai (giữ nguyên hành vi cũ cho các trường đang chạy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->unsignedSmallInteger('required_credits')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('required_credits');
        });
    }
};
