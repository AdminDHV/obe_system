<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50)->index();
            $table->string('file_path', 255);
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->string('status', 20)->default('pending')->index();
            $table->jsonb('error_log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_batches');
    }
};
