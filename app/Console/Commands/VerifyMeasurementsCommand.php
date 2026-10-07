<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\School;
use App\Services\OBE\MeasurementVerifier;
use Illuminate\Console\Command;

/**
 * Kiểm đối chiếu số đo đã lưu với phép tính lại độc lập — chạy sau deploy, sau khi dọn dữ liệu, hoặc
 * khi trường thắc mắc một con số. Chỉ ĐỌC, không ghi gì.
 *
 *   php artisan obe:verify-measurements            ← mọi trường đang hoạt động
 *   php artisan obe:verify-measurements DHV
 */
class VerifyMeasurementsCommand extends Command
{
    protected $signature = 'obe:verify-measurements {codes?* : Mã trường (bỏ trống = mọi trường đang hoạt động)}';

    protected $description = 'Kiểm đối chiếu số đo CLO/PI/PLO đã lưu với phép tính lại độc lập (chỉ đọc)';

    public function handle(MeasurementVerifier $verifier): int
    {
        $codes = $this->argument('codes');
        $schools = $codes
            ? School::whereIn('code', $codes)->orderBy('id')->get()
            : School::where('is_active', true)->orderBy('id')->get();

        $lech = 0;
        foreach ($schools as $school) {
            $kq = $verifier->verifySchool($school->id);
            $this->line(sprintf(
                '%s — %d SV · kiểm %d CLO · %d PI · %d PLO · bỏ qua %s · LỆCH %d',
                $kq['school'], $kq['students'], $kq['clo_checked'], $kq['pi_checked'], $kq['plo_checked'],
                $kq['skipped'] === [] ? '0' : json_encode($kq['skipped'], JSON_UNESCAPED_UNICODE),
                count($kq['mismatches']),
            ));
            foreach (array_slice($kq['mismatches'], 0, 30) as $m) {
                $this->error('  ' . $m);
            }
            if (count($kq['mismatches']) > 30) {
                $this->error('  … và ' . (count($kq['mismatches']) - 30) . ' chỗ lệch khác');
            }
            $lech += count($kq['mismatches']);
        }

        if ($lech > 0) {
            $this->error("Có {$lech} chỗ lệch. Thường là quên tính lại sau khi sửa điểm/khung: bấm \"Tính lại toàn trường\" rồi kiểm lại. Còn lệch thì engine sai.");

            return self::FAILURE;
        }

        $this->info('Mọi số đã kiểm đều khớp phép tính lại.');

        return self::SUCCESS;
    }
}
