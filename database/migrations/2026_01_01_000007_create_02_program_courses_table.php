<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_courses', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->boolean('is_mandatory')->default(true);
            $table->primary(['program_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_courses');
    }
};
