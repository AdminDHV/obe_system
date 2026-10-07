<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Events\GradeUpdatedEvent;
use App\Exports\ClassSheetTemplate;
use App\Imports\StructureImport;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Program;
use App\Models\School;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\ClassSheetService;
use App\Services\OBE\ObeRecalculationService;
use App\Services\OBE\SchoolPurgeService;
use App\Services\OBE\StructureBuilderService;
use Illuminate\Support\Facades\Event;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * DỮ LIỆU MẪU — bấm một nút là có các trường [DEMO] đã đo xong, xóa sạch được.
 *
 * Dựng bằng ĐÚNG hai file mà trường dùng (buổi họp lần 1): sinh File 1 "Khai báo chương trình" dạng
 * Excel thật rồi nhập qua bộ đọc khung; mỗi môn tải PHIẾU LỚP HỌC PHẦN điền sẵn từ chính file mẫu,
 * điền CLO, ma trận, câu hỏi, điểm từng câu, lưu Excel rồi nhập qua bộ đọc phiếu. Không ghi thẳng
 * vào bảng nào. Nhờ vậy dữ liệu mẫu cũng là một bài kiểm luồng nhập: mẫu phiếu và bộ đọc lệch nhau
 * thì nút "Nạp dữ liệu mẫu" hỏng ngay, không đợi tới lúc trường điền phiếu thật.
 *
 * Bộ cũ dựng khung 11 sheet, ghi thẳng điểm cả bài và để chuyên cần không gắn CLO — tức là cho người
 * xem thấy một quy trình trường không còn dùng.
 */
class DemoDataService
{
    private const GIANG_VIEN = ['TS. Nguyễn Minh Tâm', 'ThS. Lê Thu Hà', 'TS. Phạm Quốc Bảo', 'ThS. Võ Ngọc Lan'];

    public function __construct(
        private StructureBuilderService $builder,
        private ObeRecalculationService $recalc,
        private ClassSheetService $classSheets,
        private ClassSheetReader $reader,
        private SchoolPurgeService $purge,
    ) {}

    /**
     * Nạp dữ liệu mẫu cho TẤT CẢ trường mẫu (idempotent: xóa mẫu cũ trước).
     * Trả về trường mẫu đầu tiên để màn hình còn biết chuyển "Trường đang làm việc" sang đó.
     */
    public function seed(): School
    {
        $this->clear();

        $dau = null;
        foreach (DemoSchools::all() as $cfg) {
            // is_active = true để trường mẫu hiện trong ô "Trường đang làm việc".
            $school = School::create(['code' => $cfg['code'], 'name' => $cfg['name'], 'is_active' => true]);
            $dau ??= $school;

            $this->importProgramFile($school, $cfg);

            foreach ($cfg['programs'] as $viTri => $ct) {
                $program = Program::where('school_id', $school->id)->where('code', $ct['code'])->firstOrFail();
                $cohort = Cohort::where('program_id', $program->id)->where('name', $ct['cohort'])->firstOrFail();

                // Tính lại MỘT lần cho cả ngành thay vì mỗi (sinh viên, lớp) một lần — nạp mẫu chạy
                // đồng bộ nên người bấm nút thấy số ngay, không phải đợi hàng đợi.
                Event::fakeFor(function () use ($school, $program, $cohort, $ct, $viTri) {
                    foreach ($ct['courses'] as $mon) {
                        $course = Course::where('school_id', $school->id)->where('code', $mon[0])->firstOrFail();
                        $this->importClassSheet($school, $program, $cohort, $course, $ct, $mon, $viTri);
                    }
                }, [GradeUpdatedEvent::class]);

                $this->recalc->recalculateScope($school->id, $program->id, null);
            }
        }

        if ($dau === null) {
            throw new RuntimeException('DemoSchools::all() rỗng — không có trường mẫu nào để nạp.');
        }

        return $dau;
    }

    /** Xóa toàn bộ dữ liệu mẫu (chỉ các trường mang mã mẫu). Trả true nếu có xóa. */
    public function clear(): bool
    {
        $ma = array_column(DemoSchools::all(), 'code');
        $ma[] = DemoSchools::LEGACY_CODE; // bản mẫu đời cũ chỉ có 1 trường — vẫn phải dọn được

        $ids = School::withTrashed()->whereIn('code', $ma)->pluck('id')->all();
        if ($ids === []) {
            return false;
        }

        $this->purge->purge($ids);

        return true;
    }

    // ── File 1 ─────────────────────────────────────────────────────────────────────────────────

    private function importProgramFile(School $school, array $cfg): void
    {
        $nganh = [];
        $ploPi = [];
        $mon = [];
        $sv = [];

        foreach ($cfg['programs'] as $viTri => $ct) {
            $nganh[] = [$ct['code'], $ct['name'], $ct['khoa'], $ct['cohort'], $ct['year']];

            foreach ($ct['plos'] as [$maPlo, $moTaPlo, $dsPi]) {
                if ($dsPi === []) {
                    $ploPi[] = [$ct['code'], $maPlo, $moTaPlo, null, null, null];
                }
                foreach ($dsPi as $k => [$maPi, $moTaPi, $trongSo]) {
                    $ploPi[] = [$ct['code'], $maPlo, $k === 0 ? $moTaPlo : null, $maPi, $moTaPi, $trongSo];
                }
            }

            foreach ($ct['courses'] as [$maMon, $tenMon, $tinChi]) {
                $mon[] = [$ct['code'], $maMon, $tenMon, $tinChi];
            }

            // Ngành nào cũng có sĩ số khác nhau cho đỡ giả.
            for ($i = 1; $i <= 18 + $viTri * 4; $i++) {
                $sv[] = [self::mssv($ct, $i), self::hoTen($i, $viTri), $ct['code'], $ct['year']];
            }
        }

        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $this->sheet($book, 'Nganh_Khoa', ['Mã ngành', 'Tên ngành', 'Khoa quản lý', 'Tên khóa', 'Năm bắt đầu'], $nganh);
        $this->sheet($book, 'PLO_PI', ['Mã ngành', 'Mã PLO', 'Mô tả PLO', 'Mã PI', 'Mô tả PI', 'Trọng số PI'], $ploPi);
        $this->sheet($book, 'MonHoc', ['Mã ngành', 'Mã môn', 'Tên môn', 'Tín chỉ'], $mon);
        $this->sheet($book, 'SinhVien', ['MSSV', 'Họ và tên', 'Mã ngành', 'Năm khóa'], $sv);

        $path = $this->save($book);
        try {
            $import = new StructureImport(IOFactory::createReaderForFile($path)->listWorksheetNames($path));
            Excel::import($import, $path);
            $kq = $this->builder->build($import->data, $school->id);
        } finally {
            @unlink($path);
        }

        if ($kq['errors'] !== []) {
            throw new RuntimeException("File khai báo chương trình mẫu của {$cfg['code']} bị lỗi: " . implode(' | ', $kq['errors']));
        }
    }

    /** @param array<int,array<int,mixed>> $rows */
    private function sheet(Spreadsheet $book, string $title, array $headers, array $rows): void
    {
        $ws = new Worksheet($book, $title);
        $book->addSheet($ws);
        $ws->fromArray($headers, null, 'A1');
        $ws->getStyle('A:C')->getNumberFormat()->setFormatCode('@');
        $ws->fromArray($rows, null, 'A2', true);
    }

    // ── Phiếu lớp học phần ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $ct  khai báo ngành trong DemoSchools
     * @param array<int,mixed>    $mon [mã môn, tên, tín chỉ, [[CLO, mô tả, PI, mức], …], độ lệch điểm?]
     */
    private function importClassSheet(School $school, Program $program, Cohort $cohort, Course $course, array $ct, array $mon, int $viTri): void
    {
        [$maMon, , , $dsClo] = $mon;
        $maLop = "{$maMon}-{$ct['year']}-01";

        $book = (new ClassSheetTemplate())->build($school->id, $program->id, $cohort->id, $course->id);
        $info = $book->getSheetByName('KhaiBao');

        $info->setCellValue('B' . $this->rowOf($info, 'Mã lớp học phần'), $maLop);
        $info->setCellValue('B' . $this->rowOf($info, 'Giảng viên giảng dạy'), self::GIANG_VIEN[($viTri + crc32($maMon)) % count(self::GIANG_VIEN)]);
        $info->setCellValue('B' . $this->rowOf($info, 'Học kỳ'), 1);

        // Ma trận: cột PLO/PI do phiếu điền sẵn theo ngành — tìm đúng cột theo mã.
        $header = $this->rowOf($info, 'Chuẩn đầu ra');
        $colOf = [];
        for ($c = 3; $c <= 60; $c++) {
            $code = trim((string) $info->getCell(Coordinate::stringFromColumnIndex($c) . $header)->getValue());
            if ($code !== '') {
                $colOf[$code] = Coordinate::stringFromColumnIndex($c);
            }
        }
        foreach ($dsClo as $k => [$maClo, $moTa, $maPi, $muc]) {
            $r = $header + 1 + $k;
            $info->setCellValue("A{$r}", $maClo);
            $info->setCellValue("B{$r}", $moTa);
            $col = $colOf[$maPi] ?? throw new RuntimeException("Phiếu mẫu {$maMon}: ngành không có cột '{$maPi}'.");
            $info->setCellValue("{$col}{$r}", $muc);
        }

        // Bài đánh giá: phiếu mẫu gợi ý sẵn Chuyên cần 10 · Giữa kỳ 1 40 · Cuối kỳ 50 — dùng đúng bộ đó.
        // Chuyên cần GẮN CLO (đề cương thật của trường có gắn), để đo như mọi bài khác.
        $codes = array_column($dsClo, 0);
        $plan = [
            'Chuyên cần' => ['process', [['Chuyên cần', 'Chuyên cần', 10.0, end($codes)]]],
            'Giữa kỳ 1' => ['process', $this->questions($codes, 'Tự luận')],
            'Cuối kỳ' => ['final', $this->questions($codes, 'Tiểu luận')],
        ];

        foreach ($plan as $sheetName => [$loai, $qs]) {
            $ws = $book->getSheetByName($sheetName)
                ?? throw new RuntimeException("Phiếu mẫu {$maMon} thiếu sheet '{$sheetName}'.");

            for ($k = 0; $k < max(3, count($qs)); $k++) {
                $c = Coordinate::stringFromColumnIndex(4 + $k);
                [$label, $type, $max, $clo] = $qs[$k] ?? [null, null, null, null];
                $ws->setCellValue("{$c}7", $label);
                $ws->setCellValue("{$c}2", $type);
                $ws->setCellValue("{$c}3", $max);
                $ws->setCellValue("{$c}4", $clo);
            }

            for ($r = 8; $r < 8 + 200; $r++) {
                $mssv = trim((string) $ws->getCell("B{$r}")->getValue());
                if ($mssv === '') {
                    break;
                }
                $i = (int) substr($mssv, -3);
                foreach ($qs as $k => [, , $max]) {
                    $ws->setCellValue(Coordinate::stringFromColumnIndex(4 + $k) . $r, $this->score($maLop, $loai, $i, $k, (float) $max, $viTri, (float) ($mon[4] ?? 0)));
                }
            }
        }

        $path = $this->save($book);
        try {
            $kq = $this->classSheets->import($this->reader->read($path), $school->id);
        } finally {
            @unlink($path);
        }

        if ($kq['errors'] !== []) {
            throw new RuntimeException("Phiếu mẫu {$maLop} bị lỗi: " . implode(' | ', $kq['errors']));
        }
    }

    /**
     * Mỗi CLO một câu, điểm tối đa chia đều và cộng lại ĐÚNG 10 (câu cuối nhận phần lẻ).
     *
     * @param array<int,string> $codes
     * @return array<int,array{0:string,1:string,2:float,3:string}>
     */
    private function questions(array $codes, string $type): array
    {
        $n = count($codes);
        $each = round(10 / $n, 2);
        $out = [];
        foreach (array_values($codes) as $k => $clo) {
            $max = $k === $n - 1 ? round(10 - $each * ($n - 1), 2) : $each;
            $out[] = ['Câu ' . ($k + 1), $type, $max, $clo];
        }

        return $out;
    }

    /**
     * Điểm một câu. Cố ý rải để mức đạt KHÔNG phải 100% ở mọi PLO: một demo mà bảng nào cũng xanh
     * không cho người xem thấy hệ thống biết phát hiện chỗ chưa đạt. Môn được đánh dấu KHÓ (phần tử
     * thứ 5 trong DemoSchools) bị kéo điểm xuống; mỗi ngành lệch một kiểu cho các trường khác số nhau.
     */
    private function score(string $maLop, string $loai, int $i, int $k, float $max, int $viTri, float $kho): float
    {
        $mach = 0.9 + $viTri * 0.35;
        $lech = ($loai === 'final' ? 0.6 : -0.3) + (crc32($maLop) % 13) / 10;
        $diem = max(2.0, min(9.7, 4.2 + fmod($i * $mach + $lech, 5.3) + $kho));
        $ratio = max(0.0, min(1.0, $diem / 10 + ((($k * 7 + $i) % 5) - 2) * 0.04));

        // Chấm theo bậc 0,25 như giảng viên chấm thật, và không bao giờ vượt điểm tối đa.
        return min($max, round($ratio * $max * 4) / 4);
    }

    private function rowOf(Worksheet $ws, string $label): int
    {
        for ($r = 1; $r <= 300; $r++) {
            if (trim((string) $ws->getCell("A{$r}")->getValue()) === $label) {
                return $r;
            }
        }

        throw new RuntimeException("Phiếu mẫu thiếu dòng '{$label}' ở sheet {$ws->getTitle()}.");
    }

    private function save(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'demo') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private static function mssv(array $ct, int $i): string
    {
        return $ct['code'] . $ct['year'] . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    }

    /** Họ tên Việt Nam nhìn được, không phải "Sinh viên DEMO 7". */
    private static function hoTen(int $i, int $lop): string
    {
        $ho = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Phan', 'Vũ', 'Đặng', 'Bùi'];
        $dem = ['Văn', 'Thị', 'Minh', 'Ngọc', 'Quang', 'Thanh', 'Hữu', 'Gia'];
        $ten = ['An', 'Bình', 'Chi', 'Dũng', 'Giang', 'Hà', 'Khanh', 'Linh', 'Mai', 'Nam',
                'Oanh', 'Phúc', 'Quân', 'Sơn', 'Trang', 'Uyên', 'Vy', 'Xuân', 'Yến', 'Đạt',
                'Hải', 'Kiên', 'Loan'];

        return $ho[($i + $lop) % count($ho)] . ' '
            . $dem[($i * 3 + $lop) % count($dem)] . ' '
            . $ten[($i * 7 + $lop) % count($ten)];
    }
}
