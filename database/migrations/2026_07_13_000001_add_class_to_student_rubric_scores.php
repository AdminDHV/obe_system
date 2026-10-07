<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Điểm rubric (theo câu hỏi) trước đây chỉ gắn (sinh viên, tiêu chí) — mà tiêu chí thuộc ĐỀ CƯƠNG,
 * không thuộc LỚP. Một SV học lại / học 2 lớp cùng môn sẽ đè điểm của nhau, và engine (vốn tính
 * theo cặp SV–lớp) không biết lấy điểm của lớp nào.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_rubric_scores', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('student_id')
                ->constrained('course_classes')->cascadeOnDelete();

            // Nullable: dữ liệu rubric cũ (nhập tay qua màn hình lớp) chưa có lớp — vẫn hợp lệ.
            $table->unique(['student_id', 'class_id', 'rubric_criteria_id'], 'srs_student_class_criteria_uq');
        });
    }

    public function down(): void
    {
        Schema::table('student_rubric_scores', function (Blueprint $table) {
            $table->dropUnique('srs_student_class_criteria_uq');
            $table->dropConstrainedForeignId('class_id');
        });
    }
};
