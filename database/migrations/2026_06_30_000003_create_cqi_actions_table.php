<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hồ sơ CẢI TIẾN LIÊN TỤC (CQI/PDCA): mỗi PLO chưa đạt mục tiêu → 1 việc
        // cải tiến (phát hiện · nguyên nhân · hành động · phụ trách · trạng thái),
        // đóng băng mốc "trước" (attainment/target) để so sánh sau khi đo lại.
        Schema::create('cqi_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('plo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('measurement_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('attainment_percent', 5, 2)->nullable(); // mốc "trước"
            $table->decimal('target_percent', 5, 2)->nullable();
            $table->text('finding')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('action_plan')->nullable();
            $table->string('owner', 255)->nullable();
            $table->string('status', 20)->default('open'); // open | in_progress | done
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cqi_actions');
    }
};
