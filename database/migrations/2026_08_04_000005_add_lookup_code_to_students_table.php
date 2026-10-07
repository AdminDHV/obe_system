<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MÃ TRA CỨU — để học viên tự xem kết quả mà KHÔNG cần cấp tài khoản.
 *
 * Vì sao không tra bằng mỗi MSSV: mã học viên tuần tự và công khai
 * (2312HV9001, 2312HV9003, 2312HV9004…), đếm từ 1001 lên là ra cả khóa. Trong khi dữ liệu hiện
 * KHÔNG có gì để ghép làm yếu tố thứ hai — ngày sinh 0/55, CCCD 0/55, điện thoại 0/55, email 3/55.
 * Nên tra bằng MSSV trần sẽ công khai mức đạt PLO/PI và tín chỉ của mọi học viên.
 *
 * Mã này ngẫu nhiên, in sẵn trên chính phiếu kết quả khoa phát cho từng người ⇒ về bản chất là mật
 * khẩu nhưng bỏ hết gánh nặng quản lý tài khoản (không cần email, không cần SMTP, không mở thêm
 * cửa đăng nhập). Mất mã thì khoa cấp lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('lookup_code', 20)->nullable()->unique()->after('student_code');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['lookup_code']);
            $table->dropColumn('lookup_code');
        });
    }
};
