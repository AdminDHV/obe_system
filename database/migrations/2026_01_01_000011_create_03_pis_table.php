<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plo_id')->constrained('plos')->cascadeOnDelete();
            $table->string('code', 20)->index();
            $table->text('description')->nullable();
            $table->boolean('is_dummy')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pis');
    }
};
