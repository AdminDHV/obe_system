<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MA TRẬN CHUẨN ĐẦU RA CÁC HỌC PHẦN TRONG CTĐT — môn × PLO/PI, ô ghi I/R/M/A.
 *
 * Khách góp ý: file khai báo chương trình không nhập CLO (CLO nhập ở phiếu từng môn), mà nhập đúng bảng
 * "Ma trận chuẩn đầu ra các học phần trong chương trình đào tạo" của CTĐT. Bảng này là CAM KẾT của
 * chương trình: môn nào đóng góp PLO nào, ở mức nào.
 *
 * Lưu theo KHÓA: CTĐT ban hành theo khóa, khóa sau đổi ma trận không được làm đổi kết quả khóa trước.
 * Lưu tới PI: CTĐT ghi ở cấp PLO thì mức áp cho mọi PI của PLO đó; PLO không chia PI thì trỏ dòng `pis`
 * mã trùng mã PLO (cách lưu nối thẳng, dùng chung với ma trận CLO).
 *
 * Khi (khóa, môn) có dòng ở đây, engine lấy mức tại đây làm trọng số (tín chỉ × mức); ma trận CLO của
 * phiếu chỉ còn cho biết CLO nào đo PLO/PI nào. Không có dòng ⇒ giữ quy tắc cũ (mức nặng nhất của các CLO).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_outcome_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('pi_id')->constrained('pis')->cascadeOnDelete();
            $table->enum('level', ['I', 'R', 'M', 'A']);
            $table->timestamps();

            $table->unique(['cohort_id', 'course_id', 'pi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_outcome_levels');
    }
};
