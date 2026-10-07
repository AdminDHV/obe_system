<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Vai trò `viewer` — "Người xem (Cố vấn học tập)": xem toàn trường của mình, KHÔNG ghi được gì.
 *
 * Tạo bằng migration chứ không bằng seeder: production không chạy `db:seed` (DEPLOY.md), nên
 * seeder sẽ không bao giờ chạm tới máy thật. Vai trò phải có mặt ngay sau `migrate --force` thì
 * mới gán được cho tài khoản.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::findOrCreate('viewer', 'web');
    }

    public function down(): void
    {
        // Xoá vai trò là gỡ luôn quyền của mọi tài khoản đang mang nó. Chỉ xoá khi chưa ai dùng.
        $role = Role::where('name', 'viewer')->where('guard_name', 'web')->first();

        if ($role && $role->users()->count() === 0) {
            $role->delete();
        }
    }
};
