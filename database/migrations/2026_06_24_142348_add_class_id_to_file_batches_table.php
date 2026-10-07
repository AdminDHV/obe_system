<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_batches', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('user_id')->constrained('course_classes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('file_batches', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
            $table->dropColumn('class_id');
        });
    }
};
