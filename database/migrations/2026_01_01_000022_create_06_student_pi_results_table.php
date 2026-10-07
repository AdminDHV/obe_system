<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_pi_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('pi_id')->constrained('pis')->cascadeOnDelete();
            $table->decimal('achieved_score', 8, 4);
            $table->timestamps();

            $table->index(['student_id', 'pi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_pi_results');
    }
};
