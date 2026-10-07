<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_id')->constrained('course_syllabus')->cascadeOnDelete();
            $table->string('name', 100);
            $table->enum('assessment_category', ['process', 'final']);
            $table->decimal('weight_percent', 5, 2);
            $table->boolean('is_rubric_based')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
