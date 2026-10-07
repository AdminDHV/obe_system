<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('course_classes')->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->decimal('score', 8, 4)->nullable();
            $table->boolean('is_legacy_data')->default(false);
            $table->integer('attempt_number')->default(1);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->index(['student_id', 'class_id', 'assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_grades');
    }
};
