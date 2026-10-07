<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->string('short_name', 100)->nullable();
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Trường mặc định — gán cho toàn bộ dữ liệu hiện có khi nâng cấp đa-trường.
        DB::table('schools')->insert([
            'uuid' => (string) Str::uuid(),
            'code' => 'DEFAULT',
            'name' => 'Trường mặc định',
            'short_name' => 'Mặc định',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
