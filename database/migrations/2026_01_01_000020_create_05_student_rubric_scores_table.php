<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_rubric_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('rubric_criteria_id')->constrained('rubric_criteria')->cascadeOnDelete();
            $table->decimal('score', 5, 2);
            $table->timestamps();

            $table->index(['student_id', 'rubric_criteria_id'], 'srs_student_criteria_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_rubric_scores');
    }
};
