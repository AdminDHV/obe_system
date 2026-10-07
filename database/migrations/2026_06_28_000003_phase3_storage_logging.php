<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultId = DB::table('schools')->where('code', 'DEFAULT')->value('id');

        // Gắn trường + summary cho lô import; gắn trường cho báo cáo.
        Schema::table('file_batches', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->json('summary')->nullable();
        });
        DB::table('file_batches')->whereNull('school_id')->update(['school_id' => $defaultId]);

        Schema::table('report_snapshots', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
        });
        DB::table('report_snapshots')->whereNull('school_id')->update(['school_id' => $defaultId]);

        // Nhật ký mỗi lần ĐO LƯỜNG (recalc theo phạm vi): ai/lúc nào/phạm vi/bao nhiêu cặp.
        Schema::create('measurement_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained('cohorts')->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 30)->default('manual'); // manual | command | import
            $table->integer('pairs_count')->default(0);
            $table->string('status', 20)->default('completed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_runs');
        Schema::table('report_snapshots', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn('school_id');
        });
        Schema::table('file_batches', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn(['school_id', 'summary']);
        });
    }
};
