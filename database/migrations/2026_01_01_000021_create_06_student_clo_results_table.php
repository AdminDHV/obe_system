<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_clo_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('course_classes')->cascadeOnDelete();
            $table->foreignId('clo_id')->constrained('clos')->cascadeOnDelete();
            $table->decimal('achieved_score', 8, 4);
            $table->timestamps();

            $table->index(['student_id', 'class_id', 'clo_id'], 'scr_student_class_clo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_clo_results');
    }
};
