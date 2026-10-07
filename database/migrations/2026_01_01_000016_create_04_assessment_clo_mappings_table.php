<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_clo_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('clo_id')->constrained('clos')->cascadeOnDelete();
            $table->decimal('weight_in_assessment', 5, 2);
            $table->timestamps();

            $table->unique(['assessment_id', 'clo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_clo_mappings');
    }
};
