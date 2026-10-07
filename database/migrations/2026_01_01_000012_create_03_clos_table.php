<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_id')->constrained('course_syllabus')->cascadeOnDelete();
            $table->string('code', 20)->index();
            $table->text('description');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clos');
    }
};
