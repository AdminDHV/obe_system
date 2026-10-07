<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đóng băng luôn ĐỘ PHỦ vào bản chốt.
 *
 * Bản chốt tồn tại để đọc lại được "mức đạt TÍNH ĐẾN thời điểm X". Nhưng mức đạt 75% ở học kỳ 1
 * (mới đo 1/3 học phần) và 75% ở học kỳ cuối (đã đo 3/3) là hai điều hoàn toàn khác nhau — chốt số
 * mà bỏ mẫu số thì một năm sau không ai còn phân biệt được, và đó đúng là loại nhầm lẫn mà bản chốt
 * sinh ra để ngăn.
 *
 * `track` lưu dạng CHỮ (không phải khoá ngoại) cùng lý do với `outcome_code`: hướng chuyên ngành có
 * thể bị đổi tên hoặc xoá, bản đã gửi cho trường vẫn phải đọc được y như lúc gửi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurement_snapshot_students', function (Blueprint $table) {
            $table->string('track')->nullable()->after('class_group');
            $table->boolean('track_pending')->default(false)->after('track');
        });

        Schema::table('measurement_snapshot_outcomes', function (Blueprint $table) {
            $table->unsignedSmallInteger('courses_done')->nullable()->after('achieved_score');
            $table->unsignedSmallInteger('courses_planned')->nullable()->after('courses_done');
        });
    }

    public function down(): void
    {
        Schema::table('measurement_snapshot_students', function (Blueprint $table) {
            $table->dropColumn(['track', 'track_pending']);
        });

        Schema::table('measurement_snapshot_outcomes', function (Blueprint $table) {
            $table->dropColumn(['courses_done', 'courses_planned']);
        });
    }
};
