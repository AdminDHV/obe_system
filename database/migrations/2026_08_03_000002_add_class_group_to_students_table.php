<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LỚP SINH HOẠT (đơn vị mà cố vấn học tập theo dõi) — khác hẳn `course_classes` (lớp học phần).
 *
 * Vì sao cần: bảng tổng theo lớp cho khoa/CVHT không có đơn vị nào để gộp. Schema chỉ có
 * trường → khoa → ngành → khóa → lớp-học-phần. `cohorts` quá thô (gộp mọi nhóm của một năm),
 * còn `course_classes` là lớp giảng dạy, mỗi môn một lớp khác nhau.
 *
 * Mã sinh viên thực tế có mang chữ số nhóm (2412QK**3**015) nhưng CỐ Ý không suy từ đó: quy tắc
 * đặt mã là của trường, không phải hợp đồng dữ liệu — suy sai là gộp nhầm sinh viên.
 *
 * Nullable: chưa khai thì báo cáo gộp theo KHÓA như trước, không vỡ gì. Khi trường gửi danh sách
 * lớp thì nhập qua file Danh sách SV (cột "Lớp") hoặc form sinh viên.
 *
 * Nếu sau này CVHT cần quyền sở hữu lớp (chỉ xem lớp mình), hãy nâng thành bảng `student_classes`
 * riêng có `advisor_user_id` — cột này khi đó thành khoá tự nhiên để backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('class_group')->nullable()->after('cohort_id');
            $table->index(['school_id', 'cohort_id', 'class_group'], 'students_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_group_idx');
            $table->dropColumn('class_group');
        });
    }
};
