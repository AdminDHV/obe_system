<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.staff_code` đang UNIQUE TOÀN HỆ THỐNG, khác hẳn programs/courses/students/course_classes
 * vốn unique theo `(school_id, code)`. Hệ quả: hai trường KHÔNG thể cùng dùng mã GV "GV01" —
 * va ngay khi trường thứ hai lên hệ thống.
 *
 * Đổi sang `(school_id, staff_code)`. Lưu ý Postgres coi NULL != NULL nên tài khoản chưa gán
 * trường (school_id NULL) vẫn có thể trùng mã nhau — chấp nhận được vì đó là dữ liệu tạm, và
 * `StructureBuilderService` đã ưu tiên GV cùng trường khi tra.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Trùng mã trong CÙNG một trường thì unique mới sẽ chặn — báo sớm còn hơn migrate gãy giữa chừng.
        $dups = DB::table('users')
            ->select('school_id', 'staff_code', DB::raw('count(*) as n'))
            ->whereNotNull('school_id')
            ->groupBy('school_id', 'staff_code')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($dups->isNotEmpty()) {
            throw new RuntimeException(
                'Có mã giảng viên trùng trong cùng một trường, phải dọn trước: '
                . $dups->map(fn ($d) => "school_id={$d->school_id}/{$d->staff_code} ({$d->n})")->implode(', ')
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_staff_code_unique');
            $table->unique(['school_id', 'staff_code'], 'users_school_staff_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_school_staff_code_unique');
            $table->unique('staff_code', 'users_staff_code_unique');
        });
    }
};
