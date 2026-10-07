<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\School;
use App\Services\OBE\SchoolPurgeService;
use Illuminate\Console\Command;

/**
 * Xóa hẳn dữ liệu của trường thử nghiệm / dữ liệu cũ.
 *
 *   php artisan obe:purge-school SDH-QTKD SDH-QTKD-V2 --dry-run   ← luôn chạy thử trước
 *   php artisan obe:purge-school SDH-QTKD SDH-QTKD-V2
 *
 * Trường đang hoạt động bị từ chối trừ khi có --allow-active: gõ nhầm mã trường thật là tai nạn không
 * hoàn tác được. Xem SchoolPurgeService cho các lớp an toàn khác.
 */
class PurgeSchoolCommand extends Command
{
    protected $signature = 'obe:purge-school
        {codes* : Mã trường cần xóa}
        {--dry-run : Chạy thử — chỉ báo số dòng sẽ xóa, không xóa gì}
        {--allow-active : Cho phép xóa trường đang hoạt động}
        {--force : Không hỏi xác nhận}';

    protected $description = 'Xóa hẳn toàn bộ dữ liệu của trường thử nghiệm / dữ liệu cũ (có chạy thử và tự hoàn tác nếu đụng trường khác)';

    public function handle(SchoolPurgeService $purge): int
    {
        $codes = $this->argument('codes');
        $schools = School::withTrashed()->whereIn('code', $codes)->get();

        $missing = array_diff($codes, $schools->pluck('code')->all());
        if ($missing !== []) {
            $this->error('Không tìm thấy trường: ' . implode(', ', $missing));

            return self::FAILURE;
        }

        $active = $schools->where('is_active', true);
        if ($active->isNotEmpty() && ! $this->option('allow-active')) {
            $this->error('Trường đang hoạt động, không xóa: ' . $active->pluck('code')->implode(', ') . ' (thêm --allow-active nếu thật sự muốn).');

            return self::FAILURE;
        }

        $this->table(['id', 'Mã', 'Tên', 'Hoạt động'], $schools->map(fn ($s) => [$s->id, $s->code, $s->name, $s->is_active ? 'có' : 'không'])->all());

        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->option('force') && ! $this->confirm('Xóa HẲN toàn bộ dữ liệu các trường trên? Không hoàn tác được.')) {
            $this->info('Đã hủy.');

            return self::SUCCESS;
        }

        $counts = $purge->purge($schools->pluck('id')->all(), $dry);

        $this->table(['Bảng', $dry ? 'Sẽ xóa' : 'Đã xóa'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info($dry
            ? 'CHẠY THỬ — chưa xóa gì. Dữ liệu các trường khác được kiểm y nguyên.'
            : 'Đã xóa. Dữ liệu các trường khác được kiểm y nguyên trước khi chốt.');

        return self::SUCCESS;
    }
}
