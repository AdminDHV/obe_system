<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_plo_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('plo_id')->constrained('plos')->cascadeOnDelete();
            $table->decimal('achieved_score', 8, 4);
            $table->integer('level')->default(0);
            $table->timestamps();

            $table->index(['student_id', 'plo_id'], 'spr_student_plo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_plo_results');
    }
};
