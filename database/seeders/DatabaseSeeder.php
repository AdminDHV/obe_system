<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Máy dev mới: vai trò, tài khoản quản trị và trọng số mức I/R/M/A — đủ để đăng nhập.
     *
     * Không seed ngành/môn/sinh viên: dữ liệu để thử nằm ở nút "Nạp dữ liệu mẫu" (Trung tâm đo lường,
     * Bước 1), dựng qua ĐÚNG hai file mà trường dùng. Seeder cũ tạo khóa 2025 không gắn trường, tài
     * khoản sinh viên cho portal đã gỡ và PI ảo tự sinh — toàn thứ hệ thống không còn làm.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            MappingScaleWeightSeeder::class,
        ]);
    }
}
