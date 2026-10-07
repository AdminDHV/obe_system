<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('framework_plo_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('standard_frameworks')->cascadeOnDelete();
            $table->foreignId('plo_id')->constrained('plos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['framework_id', 'plo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_plo_mappings');
        Schema::dropIfExists('standard_frameworks');
    }
};
