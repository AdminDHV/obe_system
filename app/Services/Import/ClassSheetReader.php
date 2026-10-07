<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Đọc PHIẾU LỚP HỌC PHẦN thành dữ liệu thô — chưa kiểm, chưa ghi gì.
 *
 * Phiếu do trường tự điền bằng tay nên hay bị chèn thêm dòng, đổi chỗ khối. Vì vậy mọi khối được
 * tìm theo NHÃN ở cột A ("Mã môn học", "Chuẩn đầu ra", "Đánh giá", "Điểm tối đa", "STT"…) chứ không
 * theo số dòng cố định. Đọc giá trị đã tính (công thức ra số), không đọc chuỗi đã định dạng.
 *
 * Tách đọc khỏi kiểm/ghi (`ClassSheetService`) để test được phần kiểm mà không cần dựng file Excel.
 *
 * Bố cục:
 *   Sheet `KhaiBao`
 *     - khối thông tin: nhãn ở A, giá trị ở B
 *     - khối "Chuẩn đầu ra": A mã CLO · B nội dung · từ C là mã PLO/PI, ô ghi I/R/M/A
 *     - khối "Đánh giá": A tên bài · cột "Loại" (tùy chọn) · cột "Trọng số"
 *   Mỗi bài đánh giá một sheet (tên sheet = tên bài):
 *     - các dòng "Hình thức", "Điểm tối đa", "CLO cần đo", "PLO/PI cần đo" — mỗi câu một cột
 *     - dòng tiêu đề "STT · MSSV · Họ và tên · Câu 1 · Câu 2…", sinh viên ở các dòng dưới
 */
final class ClassSheetReader
{
    public const INFO_SHEET = 'KhaiBao';

    /** Nhãn khối thông tin (đã chuẩn hóa) → khóa dữ liệu. */
    private const INFO_LABELS = [
        'ma_mon' => ['ma_mon_hoc', 'ma_mon', 'ma_hoc_phan', 'ma_hp'],
        'ten_mon' => ['ten_mon_hoc', 'ten_mon', 'ten_hoc_phan'],
        'nganh' => ['ma_nganh', 'nganh'],
        'khoa' => ['khoa', 'khoa_hoc', 'ten_khoa', 'nam_khoa'],
        'ma_lop' => ['ma_lop_hoc_phan', 'ma_lop', 'lop_hoc_phan'],
        'giang_vien' => ['giang_vien_giang_day', 'giang_vien', 'giang_vien_day', 'gv_giang_day'],
        'nam_hoc' => ['nam_hoc'],
        'hoc_ky' => ['hoc_ky'],
    ];

    private const CLO_HEADER = ['chuan_dau_ra', 'chuan_dau_ra_hoc_phan', 'ma_clo', 'clo'];
    private const ASSESSMENT_HEADER = ['danh_gia', 'bai_danh_gia', 'ten_bai_danh_gia', 'cac_bai_danh_gia'];

    /** @return array<string,mixed> */
    public function read(string $path): array
    {
        $book = IOFactory::load($path);

        $sheets = [];
        foreach ($book->getWorksheetIterator() as $ws) {
            // toArray(nullValue, calculateFormulas, formatData, returnCellRef)
            $sheets[$ws->getTitle()] = $ws->toArray(null, true, false, true);
        }

        return $this->parse($sheets);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $sheets tên sheet → dòng → cột chữ → giá trị
     * @return array<string,mixed>
     */
    public function parse(array $sheets): array
    {
        $infoTitle = $this->findSheet(array_keys($sheets), self::INFO_SHEET);

        if ($infoTitle === null) {
            return ['has_info_sheet' => false, 'info' => [], 'clos' => [], 'targets' => [], 'assessments' => [], 'sheets' => []];
        }

        [$info, $clos, $targets, $assessments] = $this->parseInfo($sheets[$infoTitle]);

        $assessmentSheets = [];
        foreach ($sheets as $title => $rows) {
            if ($title === $infoTitle) {
                continue;
            }
            $assessmentSheets[$title] = $this->parseAssessmentSheet($rows);
        }

        return [
            'has_info_sheet' => true,
            'info' => $info,
            'clos' => $clos,
            'targets' => $targets,
            'assessments' => $assessments,
            'sheets' => $assessmentSheets,
        ];
    }

    /** Chuẩn hóa nhãn: bỏ dấu, thường, gạch dưới — "Mã lớp học phần" → "ma_lop_hoc_phan". */
    public static function slug(mixed $value): string
    {
        return Str::slug(trim((string) ($value ?? '')), '_');
    }

    /** Số viết kiểu Việt ("2,75") hoặc có dấu % vẫn đọc được. Không phải số ⇒ null. */
    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $s = str_replace([' ', '%'], '', trim((string) $value));
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    /** @param array<int,string> $titles */
    public function findSheet(array $titles, string $wanted): ?string
    {
        $needle = self::slug($wanted);
        foreach ($titles as $t) {
            if (self::slug($t) === $needle) {
                return $t;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<string,mixed>, 1: array<int,array<string,mixed>>, 2: array<string,string>, 3: array<int,array<string,mixed>>}
     */
    private function parseInfo(array $rows): array
    {
        ksort($rows);

        $info = [];
        $cloHeader = null;
        $assessmentHeader = null;

        foreach ($rows as $r => $cols) {
            $a = self::slug($cols['A'] ?? null);
            if ($a === '') {
                continue;
            }
            if ($cloHeader === null && in_array($a, self::CLO_HEADER, true)) {
                $cloHeader = $r;
                continue;
            }
            if ($assessmentHeader === null && in_array($a, self::ASSESSMENT_HEADER, true)) {
                $assessmentHeader = $r;
                continue;
            }
            if ($cloHeader !== null || $assessmentHeader !== null) {
                continue;
            }
            foreach (self::INFO_LABELS as $key => $aliases) {
                if (! isset($info[$key]) && in_array($a, $aliases, true)) {
                    $info[$key] = $this->text($cols['B'] ?? null);
                    $info['_cell_' . $key] = "B{$r}";
                }
            }
        }

        // Khối chuẩn đầu ra: dòng tiêu đề cho biết mỗi cột từ C là PLO/PI nào.
        $targets = [];
        $clos = [];
        if ($cloHeader !== null) {
            foreach ($rows[$cloHeader] as $col => $v) {
                if (Coordinate::columnIndexFromString($col) >= 3 && ($code = $this->text($v)) !== null) {
                    $targets[$col] = $code;
                }
            }

            // Khối kéo tới tiêu đề "Đánh giá" (hoặc hết sheet). Dòng trống ở giữa thì BỎ QUA chứ không
            // dừng: file mẫu chừa sẵn dòng trống cho CLO, và trường lỡ để trống một dòng thì các CLO
            // phía dưới không được phép biến mất mà không ai hay.
            foreach ($rows as $r => $cols) {
                if ($r <= $cloHeader) {
                    continue;
                }
                if ($assessmentHeader !== null && $r >= $assessmentHeader) {
                    break;
                }

                $map = [];
                foreach ($targets as $col => $code) {
                    $cell = $this->text($cols[$col] ?? null);
                    if ($cell !== null) {
                        $map[$code] = ['level' => mb_strtoupper($cell), 'cell' => "{$col}{$r}"];
                    }
                }

                if ($this->text($cols['A'] ?? null) === null && $this->text($cols['B'] ?? null) === null && $map === []) {
                    continue;
                }

                $clos[] = [
                    'code' => $this->text($cols['A'] ?? null),
                    'description' => $this->text($cols['B'] ?? null),
                    'map' => $map,
                    'cell' => "A{$r}",
                ];
            }
        }

        // Khối đánh giá: tìm cột "Loại" và "Trọng số" theo nhãn tiêu đề.
        $assessments = [];
        if ($assessmentHeader !== null) {
            $typeCol = null;
            $weightCol = null;
            foreach ($rows[$assessmentHeader] as $col => $v) {
                $h = self::slug($v);
                if ($col === 'A' || $h === '') {
                    continue;
                }
                if ($typeCol === null && str_starts_with($h, 'loai')) {
                    $typeCol = $col;
                } elseif ($weightCol === null && str_starts_with($h, 'trong_so')) {
                    $weightCol = $col;
                }
            }
            $weightCol ??= $typeCol === 'B' ? 'C' : 'B';

            foreach ($rows as $r => $cols) {
                if ($r <= $assessmentHeader) {
                    continue;
                }
                $name = $this->text($cols['A'] ?? null);
                if ($name === null) {
                    continue; // dòng trống chừa sẵn trong khối
                }
                $assessments[] = [
                    'name' => $name,
                    'type' => $typeCol ? $this->text($cols[$typeCol] ?? null) : null,
                    'weight' => $cols[$weightCol] ?? null,
                    'cell' => "A{$r}",
                    'weight_cell' => "{$weightCol}{$r}",
                ];
            }

            $assessments = $this->normalizeWeights($assessments);
        }

        return [$info, $clos, $targets, $assessments];
    }

    /**
     * Ô trọng số định dạng % trả về 0.1 thay vì 10. Nếu MỌI trọng số ≤ 1 và tổng ≤ 1 thì đó là
     * dạng phân số — nhân 100 để thống nhất một đơn vị (%). Giá trị không phải số giữ nguyên để
     * bước kiểm báo lỗi đúng ô.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function normalizeWeights(array $rows): array
    {
        $nums = array_map(fn ($r) => self::number($r['weight']), $rows);
        $known = array_filter($nums, fn ($n) => $n !== null);

        $isFraction = $known !== []
            && max($known) <= 1
            && array_sum($known) <= 1.0001
            && array_sum($known) > 0
            && ! array_filter($rows, fn ($r) => is_string($r['weight']) && str_contains($r['weight'], '%'));

        foreach ($rows as $i => $row) {
            $n = $nums[$i];
            $rows[$i]['weight_percent'] = $n === null ? null : ($isFraction ? round($n * 100, 4) : $n);
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string,mixed>
     */
    private function parseAssessmentSheet(array $rows): array
    {
        ksort($rows);

        $labelRows = [];
        $headerRow = null;
        $mssvCol = null;
        $nameCol = null;

        foreach ($rows as $r => $cols) {
            $a = self::slug($cols['A'] ?? null);

            if (in_array($a, ['hinh_thuc'], true)) {
                $labelRows['type'] ??= $r;
            } elseif (in_array($a, ['diem_toi_da'], true)) {
                $labelRows['max'] ??= $r;
            } elseif (in_array($a, ['clo_can_do', 'clo', 'clo_duoc_do'], true)) {
                $labelRows['clo'] ??= $r;
            } elseif (in_array($a, ['plo_pi_can_do', 'plo_pi', 'plopi_can_do', 'pi_can_do', 'plo_can_do'], true)) {
                $labelRows['target'] ??= $r;
            }

            if ($headerRow === null) {
                foreach ($cols as $col => $v) {
                    if (in_array(self::slug($v), ['mssv', 'mshv', 'ma_so_sinh_vien', 'ma_sinh_vien'], true)) {
                        $headerRow = $r;
                        $mssvCol = $col;
                        break;
                    }
                }
                if ($headerRow !== null) {
                    foreach ($cols as $col => $v) {
                        if (in_array(self::slug($v), ['ho_va_ten', 'ho_ten', 'ten_sinh_vien', 'ten'], true)) {
                            $nameCol = $col;
                        }
                    }
                }
            }
        }

        if ($headerRow === null) {
            return ['has_student_table' => false, 'questions' => [], 'students' => [], 'label_rows' => $labelRows];
        }

        // Cột câu hỏi: nằm bên phải cột MSSV / họ tên và có khai gì đó (nhãn, điểm tối đa hoặc CLO).
        $firstQuestion = max(
            Coordinate::columnIndexFromString($mssvCol),
            $nameCol ? Coordinate::columnIndexFromString($nameCol) : 0,
        ) + 1;

        $questions = [];
        $columns = array_keys($rows[$headerRow]);
        foreach (['max', 'clo', 'type'] as $k) {
            if (isset($labelRows[$k])) {
                $columns = array_merge($columns, array_keys($rows[$labelRows[$k]]));
            }
        }
        $columns = array_values(array_unique($columns));
        usort($columns, fn ($x, $y) => Coordinate::columnIndexFromString($x) <=> Coordinate::columnIndexFromString($y));

        foreach ($columns as $col) {
            if (Coordinate::columnIndexFromString($col) < $firstQuestion) {
                continue;
            }
            $label = $this->text($rows[$headerRow][$col] ?? null);
            $max = isset($labelRows['max']) ? ($rows[$labelRows['max']][$col] ?? null) : null;
            $clo = isset($labelRows['clo']) ? $this->text($rows[$labelRows['clo']][$col] ?? null) : null;

            if ($label === null && $this->text($max) === null && $clo === null) {
                continue;
            }

            $questions[] = [
                'col' => $col,
                'label' => $label ?? $col,
                'type' => isset($labelRows['type']) ? $this->text($rows[$labelRows['type']][$col] ?? null) : null,
                'max' => $max,
                'clo' => $clo,
                'target' => isset($labelRows['target']) ? $this->text($rows[$labelRows['target']][$col] ?? null) : null,
                'max_cell' => isset($labelRows['max']) ? $col . $labelRows['max'] : null,
                'clo_cell' => isset($labelRows['clo']) ? $col . $labelRows['clo'] : "{$col}{$headerRow}",
            ];
        }

        $students = [];
        foreach ($rows as $r => $cols) {
            if ($r <= $headerRow) {
                continue;
            }
            $mssv = $this->text($cols[$mssvCol] ?? null);
            $name = $nameCol ? $this->text($cols[$nameCol] ?? null) : null;

            $scores = [];
            foreach ($questions as $q) {
                $v = $cols[$q['col']] ?? null;
                $scores[$q['col']] = ($v === null || (is_string($v) && trim($v) === '')) ? null : $v;
            }

            if ($mssv === null && $name === null && array_filter($scores, fn ($v) => $v !== null) === []) {
                continue; // dòng trống
            }

            $students[] = ['mssv' => $mssv, 'name' => $name, 'row' => $r, 'scores' => $scores];
        }

        return [
            'has_student_table' => true,
            'questions' => $questions,
            'students' => $students,
            'label_rows' => $labelRows,
        ];
    }

    private function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (string) (int) $v; // 2026.0 → "2026"
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
