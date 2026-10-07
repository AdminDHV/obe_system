<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Vai trò đang dùng. Không còn 'student': portal sinh viên đã gỡ.
        $roleSuperAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'lecturer', 'guard_name' => 'web']);
        // "Người xem (Cố vấn học tập)" — xem toàn trường, không ghi. Trên production vai trò này
        // do migration 2026_08_20_000001 tạo, vì production không chạy db:seed.
        Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        // Quản trị hệ thống — không gắn trường (chuyển trường tự do). Chỉ dùng trên máy dev.
        $admin = User::firstOrCreate(
            ['email' => 'admin@dhv.edu.vn'],
            [
                'staff_code' => 'ADMIN001',
                'full_name' => 'Admin Hệ thống',
                'position' => 'Quản trị viên',
                'password' => Hash::make(env('SEED_DEFAULT_PASSWORD', 'password')),
                'employment_status' => 'full_time',
                'is_active' => true,
            ],
        );
        $admin->assignRole($roleSuperAdmin);
    }
}
