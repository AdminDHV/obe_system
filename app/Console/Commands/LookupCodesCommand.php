<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cohort;
use App\Models\Program;
use App\Models\School;
use App\Models\Student;
use App\Services\OBE\StudentLookupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Cấp MÃ TRA CỨU hàng loạt và xuất danh sách để khoa trộn thư / in phát.
 *
 * CỐ Ý KHÔNG in mã ra màn hình: mã là bí mật của từng học viên, in ra terminal là dính vào
 * scrollback, lịch sử shell và log CI. Danh sách được ghi ra FILE, lệnh chỉ báo đường dẫn.
 */
class LookupCodesCommand extends Command
{
    protected $signature = 'obe:lookup-codes
        {--school= : Mã trường (bắt buộc)}
        {--program= : Mã ngành, bỏ trống = mọi ngành của trường}
        {--cohort= : Tên khóa, bỏ trống = mọi khóa}
        {--regenerate : Cấp LẠI mã cho cả những học viên đã có (mã cũ mất hiệu lực ngay)}';

    protected $description = 'Cấp mã tra cứu cho học viên và xuất danh sách ra file CSV';

    public function handle(StudentLookupService $lookup): int
    {
        $schoolCode = (string) $this->option('school');
        if ($schoolCode === '') {
            $this->error('Thiếu --school. Ví dụ: php artisan obe:lookup-codes --school=SDH-QTKD-V3');

            return self::FAILURE;
        }

        $school = School::where('code', $schoolCode)->first();
        if (! $school) {
            $this->error("Không tìm thấy trường có mã '{$schoolCode}'.");

            return self::FAILURE;
        }

        $query = Student::where('school_id', $school->id);

        if ($programCode = $this->option('program')) {
            $program = Program::where('school_id', $school->id)->where('code', $programCode)->first();
            if (! $program) {
                $this->error("Không tìm thấy ngành '{$programCode}' trong trường {$schoolCode}.");

                return self::FAILURE;
            }
            $query->where('program_id', $program->id);
        }

        if ($cohortName = $this->option('cohort')) {
            $cohort = Cohort::where('school_id', $school->id)->where('name', $cohortName)->first();
            if (! $cohort) {
                $this->error("Không tìm thấy khóa '{$cohortName}' trong trường {$schoolCode}.");

                return self::FAILURE;
            }
            $query->where('cohort_id', $cohort->id);
        }

        $students = $query->orderBy('student_code')->get();
        if ($students->isEmpty()) {
            $this->warn('Không có học viên nào trong phạm vi đã chọn.');

            return self::SUCCESS;
        }

        $regenerate = (bool) $this->option('regenerate');
        if ($regenerate && ! $this->confirmToProceed($students->count())) {
            return self::SUCCESS;
        }

        $capMoi = 0;
        $rows = [];
        foreach ($students as $student) {
            $daCo = $student->lookup_code !== null;
            $code = $regenerate ? $lookup->regenerate($student) : $lookup->ensureCode($student);
            $capMoi += $daCo ? 0 : 1;

            $rows[] = [
                $student->student_code,
                $student->full_name,
                $student->class_group ?? '',
                $lookup->format($code),
            ];
        }

        $path = $this->write($school->code, $rows);

        $this->info("✓ {$students->count()} học viên · cấp mã mới cho {$capMoi} người.");
        $this->line('  Danh sách: <comment>' . $path . '</comment>');
        $this->newLine();
        $this->warn('  File này chứa MÃ RIÊNG của từng học viên — phát cho đúng người, đừng gửi cả danh sách vào nhóm lớp.');

        return self::SUCCESS;
    }

    private function confirmToProceed(int $count): bool
    {
        $this->warn("Cấp LẠI mã cho {$count} học viên: mã cũ đã phát sẽ KHÔNG dùng được nữa.");

        return $this->confirm('Tiếp tục?', false);
    }

    /** @param array<int,array<int,string>> $rows */
    private function write(string $schoolCode, array $rows): string
    {
        $csv = chr(0xEF) . chr(0xBB) . chr(0xBF);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['MSSV', 'Họ và tên', 'Lớp', 'Mã tra cứu']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv .= stream_get_contents($handle);
        fclose($handle);

        $name = 'lookup-codes/' . $schoolCode . '_' . now()->format('Ymd_His') . '.csv';
        Storage::disk('local')->put($name, $csv);

        return Storage::disk('local')->path($name);
    }
}
