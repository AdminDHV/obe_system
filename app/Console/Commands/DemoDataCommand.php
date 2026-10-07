<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\DemoDataService;
use App\Services\Demo\DemoSchools;
use Illuminate\Console\Command;

class DemoDataCommand extends Command
{
    protected $signature = 'obe:demo {--clear : Xóa dữ liệu mẫu thay vì nạp}';
    protected $description = 'Nạp / xóa dữ liệu mẫu (các trường mang nhãn [DEMO]) để thử toàn bộ quy trình đo lường';

    public function handle(DemoDataService $demo): int
    {
        if ($this->option('clear')) {
            $removed = $demo->clear();
            $this->info($removed ? 'Đã xóa toàn bộ trường mẫu.' : 'Không có dữ liệu mẫu để xóa.');
            return self::SUCCESS;
        }

        $demo->seed();

        foreach (DemoSchools::all() as $cfg) {
            $t = \App\Models\School::where('code', $cfg['code'])->first();
            $this->line(sprintf(
                '  %-9s %-38s ngành=%d  SV=%d',
                $cfg['code'],
                $cfg['name'],
                \App\Models\Program::where('school_id', $t->id)->count(),
                \App\Models\Student::where('school_id', $t->id)->count(),
            ));
        }

        $this->info('Đã nạp xong. Chọn trường ở ô "Trường đang làm việc" để xem.');
        return self::SUCCESS;
    }
}
