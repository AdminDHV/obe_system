<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** bảng => cột mã đang unique toàn cục (đổi sang composite theo school). */
    private array $scoped = [
        'departments' => 'code',
        'programs' => 'code',
        'courses' => 'code',
        'students' => 'student_code',
        'course_classes' => 'class_code',
    ];

    /** các bảng thêm school_id (gồm cohorts không có unique mã). */
    private array $withSchool = ['departments', 'programs', 'courses', 'students', 'course_classes', 'cohorts'];

    public function up(): void
    {
        $defaultId = DB::table('schools')->where('code', 'DEFAULT')->value('id');

        foreach ($this->withSchool as $t) {
            if (! Schema::hasColumn($t, 'school_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->foreignId('school_id')->nullable()->constrained('schools')->cascadeOnDelete();
                });
            }
            DB::table($t)->whereNull('school_id')->update(['school_id' => $defaultId]);
        }

        // Đổi unique toàn cục -> composite (school_id, <mã>): 2 trường được trùng mã.
        foreach ($this->scoped as $t => $col) {
            Schema::table($t, function (Blueprint $table) use ($col) {
                $table->dropUnique([$col]);
                $table->unique(['school_id', $col]);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->scoped as $t => $col) {
            Schema::table($t, function (Blueprint $table) use ($col) {
                $table->dropUnique(['school_id', $col]);
                $table->unique([$col]);
            });
        }
        foreach ($this->withSchool as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropForeign(['school_id']);
                $table->dropColumn('school_id');
            });
        }
    }
};
