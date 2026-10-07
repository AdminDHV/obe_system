<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Đợt khảo sát (campaign) đo gián tiếp 1 nhóm bên liên quan cho 1 ngành (+khóa).
        Schema::create('survey_instruments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('stakeholder', 20); // course_end | graduating | alumni | employer
            $table->string('label', 255)->nullable();
            $table->unsignedTinyInteger('likert_max')->default(5);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['school_id', 'program_id', 'cohort_id', 'stakeholder'], 'survey_instr_scope_unique');
        });

        // Kết quả khảo sát theo PLO (tổng hợp — KHÔNG per-respondent).
        Schema::create('survey_plo_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_instrument_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plo_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('respondents')->default(0);
            $table->decimal('mean_likert', 8, 4);
            // (mean−1)/(likert_max−1) → 0..1, cùng thang WeightedAttainment.
            $table->decimal('attainment_ratio', 8, 4);
            $table->timestamps();
            $table->unique(['survey_instrument_id', 'plo_id'], 'survey_plo_unique');
        });

        // Trọng số trộn trực tiếp/gián tiếp theo Trường (mặc định 0.8/0.2).
        Schema::table('schools', function (Blueprint $table) {
            $table->decimal('direct_weight', 5, 4)->default(0.8);
            $table->decimal('indirect_weight', 5, 4)->default(0.2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_plo_results');
        Schema::dropIfExists('survey_instruments');
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['direct_weight', 'indirect_weight']);
        });
    }
};
