<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clo_pi_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clo_id')->constrained('clos')->cascadeOnDelete();
            $table->foreignId('pi_id')->constrained('pis')->cascadeOnDelete();
            $table->enum('mapping_level', ['I', 'R', 'M', 'A']);
            $table->timestamps();

            $table->unique(['clo_id', 'pi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clo_pi_mappings');
    }
};
