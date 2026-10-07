<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_class_student', function (Blueprint $table) {
            $table->timestamp('enrolled_at')->nullable()->after('student_id');
            $table->string('status', 20)->default('active')->after('enrolled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('course_class_student', function (Blueprint $table) {
            $table->dropColumn(['enrolled_at', 'status']);
        });
    }
};
