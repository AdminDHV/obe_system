<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cách ly cứng per-user (tenant): gán mỗi tài khoản vận hành vào 1 trường.
 * - Non-super_admin bị KẸP vào school_id này (không đổi trường được — xem WorkingSchool::resolve).
 * - super_admin để NULL: operator toàn hệ thống, chuyển trường tự do qua switcher.
 * nullOnDelete: xoá trường KHÔNG xoá người vận hành, chỉ gỡ liên kết.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'school_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('school_id')->nullable()->after('department_id')
                    ->constrained('schools')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn('school_id');
        });
    }
};
