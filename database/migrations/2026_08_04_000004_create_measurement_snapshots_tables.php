<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHỐT SỔ KẾT QUẢ ĐO — trả lời được "mức đạt TÍNH ĐẾN thời điểm X".
 *
 * Vì sao cần: `student_pi_results`/`student_plo_results` chỉ giữ TRẠNG THÁI MỚI NHẤT (upsert ghi đè,
 * dòng cũ xoá cứng, không Auditable). `report_snapshots` chỉ lưu FILE kết quả và còn bị dọn sau 30
 * ngày; `measurement_runs` ghi *ai chạy* chứ không ghi *ra số gì*. Nên báo cáo đã gửi cho trường
 * KHÔNG dựng lại được — mỗi lần "Tính lại" là số cũ biến mất.
 *
 * Cố ý CHỐT SỔ THEO CHỦ ĐÍCH, không lưu mọi lần đo: đo lại chạy liên tục theo từng lần nhập điểm,
 * lưu hết vừa phình vừa vô nghĩa. Cái trường cần là "bản đã gửi ngày X dựa trên số nào".
 *
 * Lưu kèm MÃ (`outcome_code`, `student_code`) chứ không chỉ id: khung có thể đổi về sau, bản chốt
 * vẫn phải đọc được đúng như lúc gửi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained('cohorts')->nullOnDelete();
            $table->string('class_group')->nullable();
            $table->string('label');
            $table->boolean('on_track_only')->default(false);
            $table->decimal('pass_threshold', 8, 4);
            $table->decimal('target_percent', 8, 4);
            $table->unsignedInteger('students_total')->default(0);
            $table->unsignedInteger('students_counted')->default(0);
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'program_id', 'cohort_id']);
        });

        Schema::create('measurement_snapshot_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('measurement_snapshots')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('student_code');
            $table->string('full_name');
            $table->string('class_group')->nullable();
            $table->decimal('credits_earned', 8, 2)->default(0);
            $table->unsignedInteger('required_credits')->nullable();
            $table->decimal('completion_percent', 6, 2)->nullable();
            $table->boolean('on_track')->default(true);
            $table->boolean('counted')->default(true);

            $table->unique(['snapshot_id', 'student_code'], 'mss_snapshot_student_unique');
        });

        Schema::create('measurement_snapshot_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('measurement_snapshots')->cascadeOnDelete();
            $table->string('student_code');
            $table->string('outcome_type', 8);          // 'plo' | 'pi'
            $table->string('outcome_code', 40);
            $table->decimal('achieved_score', 8, 4);

            $table->unique(['snapshot_id', 'student_code', 'outcome_type', 'outcome_code'], 'mso_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_snapshot_outcomes');
        Schema::dropIfExists('measurement_snapshot_students');
        Schema::dropIfExists('measurement_snapshots');
    }
};
