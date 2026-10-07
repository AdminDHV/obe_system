<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_id')->constrained('course_syllabus')->cascadeOnDelete();
            $table->foreignId('cohort_id')->constrained('cohorts')->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained('users')->cascadeOnDelete();
            $table->string('class_code', 100)->unique()->index();
            $table->string('academic_year', 20);
            $table->integer('semester');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_classes');
    }
};
