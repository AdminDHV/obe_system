<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhóm học phần trong chương trình đào tạo.
 *
 * Trước đây CTĐT chỉ có `program_courses.is_mandatory` (bắt buộc / tự chọn), không diễn tả được
 * cấu trúc thật của trường: "học đủ 45 tín chỉ bắt buộc, rồi CHỌN MỘT hướng chuyên ngành 15 tín chỉ".
 * Thiếu chỗ này thì không thể biết lộ trình riêng của từng học viên, mà không biết lộ trình thì
 * không tính được độ phủ "PI1.1 (1/3)" — học viên hướng Y tế sẽ bị đếm cả học phần hướng Marketing
 * mà họ không bao giờ học, nên vĩnh viễn thấy thiếu.
 *
 * `required_credits` để trên NHÓM (không phải đếm số môn) vì trường thiết kế theo tín chỉ: một nhóm
 * có thể mở 5 môn nhưng chỉ yêu cầu học 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 255);
            // mandatory: ai cũng học · elective: học viên chọn nhóm (hướng chuyên ngành)
            $table->string('kind', 20)->default('elective');
            $table->decimal('required_credits', 5, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Mã nhóm chỉ cần duy nhất TRONG một chương trình — programs đã gắn school_id nên
            // không cần (và không được) unique toàn hệ thống.
            $table->unique(['program_id', 'code']);
            $table->index(['school_id', 'program_id']);
        });

        Schema::table('program_courses', function (Blueprint $table) {
            $table->foreignId('curriculum_group_id')->nullable()
                ->after('course_id')
                ->constrained('curriculum_groups')
                ->nullOnDelete();
        });

        // Hướng chuyên ngành mà học viên đã chọn. Để nhiều-nhiều vì đại học có thể có hơn một
        // khối tự chọn (chuyên sâu + tự chọn tự do); cao học hiện chỉ chọn một.
        Schema::create('curriculum_group_student', function (Blueprint $table) {
            $table->foreignId('curriculum_group_id')->constrained('curriculum_groups')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['curriculum_group_id', 'student_id']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_group_student');

        Schema::table('program_courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('curriculum_group_id');
        });

        Schema::dropIfExists('curriculum_groups');
    }
};
