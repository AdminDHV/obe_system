<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cohort_id')->nullable()->constrained('cohorts')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('student_code', 50)->unique()->index();
            $table->string('citizen_id', 20)->unique()->nullable();
            $table->string('full_name', 255);
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable();
            $table->string('email', 255)->unique()->nullable();
            $table->string('personal_email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('permanent_address')->nullable();
            $table->text('contact_address')->nullable();
            $table->date('admission_date')->nullable();
            $table->string('status', 50)->default('studying');
            $table->string('avatar', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
