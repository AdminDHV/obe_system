<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hai yêu cầu từ buổi họp 03/10/2026 với trường.
 *
 * VẮNG THI (`student_grades.is_absent`): trường ghi VT chứ không để trống. Trước đây VT chỉ được bỏ
 * qua lúc nhập nên trong DB không phân biệt được "vắng thi" với "chưa có điểm". Nay học viên vắng
 * thi cuối kỳ có một dòng điểm bài rỗng mang cờ này, và đứng ngoài cỡ mẫu của môn cho tới khi có
 * điểm thi lại (lớp 40 bạn, 2 bạn VT thì đo trên 38).
 *
 * KHUNG THEO ĐỢT (`course_syllabus.frame_locked_at`): kỹ thuật của trường dựng CLO, ma trận CLO và
 * bài đánh giá một lần cho cả đợt. Đề cương đã dựng theo khung thì phiếu giáo viên nộp lên chỉ
 * mang câu hỏi và điểm — phần khai báo trong phiếu không được ghi đè khung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_grades', function (Blueprint $table) {
            $table->boolean('is_absent')->default(false)->after('score');
        });

        Schema::table('course_syllabus', function (Blueprint $table) {
            $table->timestamp('frame_locked_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('student_grades', function (Blueprint $table) {
            $table->dropColumn('is_absent');
        });

        Schema::table('course_syllabus', function (Blueprint $table) {
            $table->dropColumn('frame_locked_at');
        });
    }
};
