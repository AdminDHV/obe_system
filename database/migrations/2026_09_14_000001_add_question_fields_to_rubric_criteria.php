<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mỗi CÂU HỎI trên phiếu lớp học phần là một tiêu chí riêng.
 *
 * Trước đây tiêu chí gộp theo (bài đánh giá × CLO): hai câu cùng đo CLO1 bị nhập làm một, và điểm
 * tối đa của câu sau đè câu trước. Phiếu mới có mỗi câu một cột (hình thức, điểm tối đa, CLO riêng),
 * nên cần biết tiêu chí là câu nào để nhập lại phiếu thì cập nhật đúng câu đó.
 *
 * `class_id`: mỗi lớp của cùng một môn có thể ra ĐỀ KHÁC NHAU, trong khi bài đánh giá (và rubric)
 * dùng chung cho mọi lớp của môn. Không gắn lớp thì nhập phiếu lớp B sẽ đè "Câu 1" của lớp A.
 * Để trống = tiêu chí dùng chung như trước (dữ liệu nhập theo cách cũ).
 *
 * Công thức đo không đổi: CLO của một bài = Σ điểm đạt / Σ điểm tối đa của mọi tiêu chí cùng CLO mà
 * sinh viên CÓ điểm — nên tiêu chí của lớp khác không lọt vào số của lớp này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubric_criteria', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('rubric_id')
                ->constrained('course_classes')->cascadeOnDelete();
            $table->string('question_label', 100)->nullable()->after('clo_id');
            $table->string('question_type', 50)->nullable()->after('question_label');
        });
    }

    public function down(): void
    {
        Schema::table('rubric_criteria', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_id');
            $table->dropColumn(['question_label', 'question_type']);
        });
    }
};
