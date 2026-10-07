<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Đặt MẬT KHẨU MẠNH ngẫu nhiên cho các tài khoản LOGIN người thật (go-live).
 *
 * Chỉ áp cho user có ROLE (super_admin/lecturer/student) và email KHÔNG phải `@system.local`
 * (bỏ qua tài khoản tổng hợp do import tạo — đã random-pass, không đăng nhập, là carrier dữ liệu).
 * IN bảng email→mật khẩu 1 LẦN ra màn hình (KHÔNG ghi file, KHÔNG commit). Chạy TAY khi deploy.
 */
class ResetPasswordsCommand extends Command
{
    protected $signature = 'obe:reset-passwords {--length=18 : Độ dài mật khẩu}';

    protected $description = 'Đặt mật khẩu mạnh ngẫu nhiên cho các tài khoản login người thật, in ra 1 lần';

    public function handle(): int
    {
        $length = max(12, (int) $this->option('length'));

        $users = User::whereHas('roles')
            ->where('email', 'not like', '%@system.local')
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('Không có tài khoản login người thật nào để đổi mật khẩu.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($users as $user) {
            $plain = Str::password($length, symbols: false); // chữ hoa/thường + số, dễ gõ, không ký tự đặc biệt
            $user->forceFill(['password' => $plain])->saveQuietly(); // cast 'hashed' tự băm; saveQuietly = không audit
            $rows[] = [$user->id, $user->email, $user->getRoleNames()->implode(', '), $plain];
        }

        $this->newLine();
        $this->error('  ⚠  MẬT KHẨU CHỈ HIỆN 1 LẦN — LƯU LẠI NGAY (không ghi ra file/log).');
        $this->table(['ID', 'Email', 'Vai trò', 'Mật khẩu mới'], $rows);
        $this->info('Đã đổi mật khẩu cho ' . count($rows) . ' tài khoản. Tài khoản @system.local giữ nguyên (an toàn sẵn).');

        return self::SUCCESS;
    }
}
