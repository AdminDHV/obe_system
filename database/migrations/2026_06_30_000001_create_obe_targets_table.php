<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mục tiêu mức đạt (%) theo từng PLO (hoặc PI) cho mỗi Trường (tenant).
        // Không có dòng → engine fallback về `obe.target_attainment_percent` (global 70),
        // nên luồng cũ giữ nguyên hành vi khi chưa khai mục tiêu riêng.
        Schema::create('obe_targets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 10); // 'plo' | 'pi'
            $table->foreignId('plo_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('pi_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('target_percent', 5, 2);
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Best-effort: 1 mục tiêu / (trường, loại, outcome). Tính đúng đảm bảo
            // ở tầng service bằng updateOrCreate (NULL trong unique index không
            // chặn trùng trên MySQL — đừng dựa hoàn toàn vào index này).
            $table->unique(['school_id', 'target_type', 'plo_id', 'pi_id'], 'obe_targets_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obe_targets');
    }
};
