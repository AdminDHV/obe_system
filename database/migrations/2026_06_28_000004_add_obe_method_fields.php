<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trọng số PI trong PLO (null = các PI của 1 PLO có trọng số bằng nhau).
        Schema::table('pis', function (Blueprint $table) {
            $table->decimal('weight', 8, 4)->nullable();
        });

        // Phương pháp đo PI/PLO của trường (cắm-được): weighted_attainment (chuẩn khách) | standard_average (cũ).
        Schema::table('schools', function (Blueprint $table) {
            $table->string('obe_method', 40)->default('weighted_attainment');
        });
        DB::table('schools')->where(fn ($q) => $q->whereNull('obe_method')->orWhere('obe_method', ''))
            ->update(['obe_method' => 'weighted_attainment']);

        // Mức đóng góp I/R/M/A theo công thức khách: I=1, R=2, M=3, A=3 (sửa được sau).
        foreach (['I' => 1, 'R' => 2, 'M' => 3, 'A' => 3] as $level => $weight) {
            DB::table('mapping_scale_weights')->updateOrInsert(
                ['level' => $level],
                ['weight_percent' => $weight, 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::table('pis', fn (Blueprint $table) => $table->dropColumn('weight'));
        Schema::table('schools', fn (Blueprint $table) => $table->dropColumn('obe_method'));
    }
};
