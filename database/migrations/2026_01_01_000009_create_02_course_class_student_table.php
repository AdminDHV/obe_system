<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_class_student', function (Blueprint $table) {
            $table->foreignId('class_id')->constrained('course_classes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->primary(['class_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_class_student');
    }
};
