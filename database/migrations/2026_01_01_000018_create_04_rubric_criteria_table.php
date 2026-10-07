<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained('rubrics')->cascadeOnDelete();
            $table->foreignId('clo_id')->constrained('clos')->cascadeOnDelete();
            $table->decimal('max_score', 5, 2);
            $table->text('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_criteria');
    }
};
