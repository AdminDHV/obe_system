<?php

declare(strict_types=1);

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Đọc FILE KHUNG HỌC PHẦN THEO ĐỢT — kỹ thuật của trường khai một lần cho cả một khóa, một năm học:
 * CLO và ma trận CLO → PLO/PI của từng môn, bài đánh giá, danh sách lớp học phần kèm giảng viên.
 * Giáo viên sau đó chỉ tải phiếu điền sẵn của lớp mình, điền câu hỏi và điểm, rồi nộp.
 *
 * Bố cục (mỗi sheet một bảng, dòng đầu là tiêu đề cột):
 *   CLO         Mã ngành · Khóa · Mã môn · Tên môn · Mã CLO · Nội dung CLO · mỗi PLO/PI một cột (I/R/M/A)
 *   DanhGia     Mã ngành · Khóa · Mã môn · Tên môn · Tên bài · Loại · Trọng số (%)
 *   LopHocPhan  Mã ngành · Khóa · Mã môn · Tên môn · Mã lớp học phần · Mã giảng viên · Giảng viên · Năm học · Học kỳ
 *
 * Mã ngành, Khóa, Mã môn để trống thì lấy theo dòng trên (kiểu bảng gộp ô quen dùng trong Excel).
 * Tên môn chỉ để người điền dễ đọc, không dùng để tra.
 *
 * Trả về đúng cấu trúc mà bộ kiểm khai báo của phiếu lớp (`ClassSheetService::validateDeclarations`)
 * nhận, để file khung và phiếu lớp cùng chung một bộ luật.
 */
final class CourseFrameReader
{
    public const CLO_SHEET = 'CLO';
    public const ASSESSMENT_SHEET = 'DanhGia';
    public const CLASS_SHEET = 'LopHocPhan';

    /** Nhãn cột (đã chuẩn hóa) → khóa dữ liệu. */
    private const COLUMNS = [
        'nganh' => ['ma_nganh', 'nganh'],
        'khoa' => ['khoa', 'khoa_hoc', 'ten_khoa', 'nam_khoa'],
        'mon' => ['ma_mon', 'ma_mon_hoc', 'ma_hoc_phan', 'ma_hp'],
        'ten_mon' => ['ten_mon', 'ten_mon_hoc', 'ten_hoc_phan'],
        'clo' => ['ma_clo', 'clo'],
        'mo_ta' => ['noi_dung', 'noi_dung_clo', 'mo_ta', 'mo_ta_clo'],
        'bai' => ['ten_bai', 'ten_bai_danh_gia', 'bai_danh_gia', 'danh_gia'],
        'loai' => ['loai', 'loai_bai'],
        'trong_so' => ['trong_so'],
        'ma_lop' => ['ma_lop_hoc_phan', 'ma_lop', 'lop_hoc_phan'],
        'giang_vien' => ['giang_vien_giang_day', 'giang_vien', 'giang_vien_day', 'gv_giang_day', 'ho_ten_giang_vien'],
        'ma_gv' => ['ma_giang_vien', 'ma_gv', 'ma_so_giang_vien', 'ma_can_bo'],
        'nam_hoc' => ['nam_hoc'],
        'hoc_ky' => ['hoc_ky'],
    ];

    /** Cột ở sheet CLO không phải mã PLO/PI — có thì bỏ qua, không báo "không có PLO/PI mã đó". */
    private const NOT_TARGET = ['stt', 'tt', 'tin_chi', 'so_tin_chi', 'ghi_chu'];

    /** @return array<string,mixed> */
    public function read(string $path): array
    {
        $book = IOFactory::load($path);

        $sheets = [];
        foreach ($book->getWorksheetIterator() as $ws) {
            $sheets[$ws->getTitle()] = $ws->toArray(null, true, false, true);
        }

        return $this->parse($sheets);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $sheets tên sheet → dòng → cột chữ → giá trị
     * @return array{
     *   sheets: array{clo: ?string, danh_gia: ?string, lop: ?string},
     *   targets: array<string,string>,
     *   clos: array<int,array<string,mixed>>,
     *   assessments: array<int,array<string,mixed>>,
     *   classes: array<int,array<string,mixed>>,
     *   errors: array<int,string>
     * }
     */
    public function parse(array $sheets): array
    {
        $reader = new ClassSheetReader();
        $titles = array_keys($sheets);
        $cloTitle = $reader->findSheet($titles, self::CLO_SHEET);
        $dgTitle = $reader->findSheet($titles, self::ASSESSMENT_SHEET);
        $lopTitle = $reader->findSheet($titles, self::CLASS_SHEET);

        $out = [
            'sheets' => ['clo' => $cloTitle, 'danh_gia' => $dgTitle, 'lop' => $lopTitle],
            'targets' => [],
            'clos' => [],
            'assessments' => [],
            'classes' => [],
            'errors' => [],
        ];

        if ($cloTitle !== null) {
            [$out['targets'], $out['clos']] = $this->parseClos($sheets[$cloTitle], $cloTitle, $out['errors']);
        }
        if ($dgTitle !== null) {
            $out['assessments'] = $this->parseAssessments($sheets[$dgTitle], $dgTitle, $reader, $out['errors']);
        }
        if ($lopTitle !== null) {
            $out['classes'] = $this->parseClasses($sheets[$lopTitle], $lopTitle, $out['errors']);
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int,string> $errors
     * @return array{0: array<string,string>, 1: array<int,array<string,mixed>>}
     */
    private function parseClos(array $rows, string $title, array &$errors): array
    {
        [$headerRow, $cols, $extra] = $this->header($rows, ['mon', 'clo']);
        if ($headerRow === null) {
            $errors[] = "{$title}: không thấy dòng tiêu đề (cần ít nhất cột 'Mã môn' và 'Mã CLO').";
            return [[], []];
        }

        // Mọi cột còn lại có tiêu đề là một mã PLO/PI.
        $targets = [];
        foreach ($extra as $col => $label) {
            if (! in_array(ClassSheetReader::slug($label), self::NOT_TARGET, true)) {
                $targets[$col] = $label;
            }
        }

        $out = [];
        $carry = ['nganh' => null, 'khoa' => null, 'mon' => null];
        foreach ($rows as $r => $line) {
            if ($r <= $headerRow) {
                continue;
            }
            $key = $this->keyOf($line, $cols, $carry);

            $map = [];
            foreach ($targets as $col => $code) {
                $cell = $this->text($line[$col] ?? null);
                if ($cell !== null) {
                    $map[$code] = ['level' => mb_strtoupper($cell), 'cell' => "{$col}{$r}"];
                }
            }
            $code = $this->text($line[$cols['clo']] ?? null);
            $desc = isset($cols['mo_ta']) ? $this->text($line[$cols['mo_ta']] ?? null) : null;

            // Dòng chừa sẵn cho môn chưa khai CLO (chỉ có mã môn) thì bỏ qua.
            if ($code === null && $desc === null && $map === []) {
                continue;
            }

            $out[] = $key + [
                'code' => $code,
                'description' => $desc,
                'map' => $map,
                'cell' => $cols['clo'] . $r,
                'row' => $r,
            ];
        }

        return [$targets, $out];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int,string> $errors
     * @return array<int,array<string,mixed>>
     */
    private function parseAssessments(array $rows, string $title, ClassSheetReader $reader, array &$errors): array
    {
        [$headerRow, $cols] = $this->header($rows, ['mon', 'bai', 'trong_so']);
        if ($headerRow === null) {
            $errors[] = "{$title}: không thấy dòng tiêu đề (cần cột 'Mã môn', 'Tên bài' và 'Trọng số').";
            return [];
        }

        $groups = [];
        $carry = ['nganh' => null, 'khoa' => null, 'mon' => null];
        foreach ($rows as $r => $line) {
            if ($r <= $headerRow) {
                continue;
            }
            $key = $this->keyOf($line, $cols, $carry);
            $name = $this->text($line[$cols['bai']] ?? null);
            if ($name === null) {
                continue;
            }
            $groups[$key['nganh'] . '|' . $key['khoa'] . '|' . $key['mon']][] = $key + [
                'name' => $name,
                'type' => isset($cols['loai']) ? $this->text($line[$cols['loai']] ?? null) : null,
                'weight' => $line[$cols['trong_so']] ?? null,
                'cell' => $cols['bai'] . $r,
                'weight_cell' => $cols['trong_so'] . $r,
                'row' => $r,
            ];
        }

        // Trọng số chuẩn hóa theo TỪNG MÔN, đúng như phiếu lớp (0,4 / 0,6 hay 40 / 60 đều được).
        $out = [];
        foreach ($groups as $rowsOfCourse) {
            array_push($out, ...$reader->normalizeWeights($rowsOfCourse));
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int,string> $errors
     * @return array<int,array<string,mixed>>
     */
    private function parseClasses(array $rows, string $title, array &$errors): array
    {
        [$headerRow, $cols] = $this->header($rows, ['mon', 'ma_lop']);
        if ($headerRow === null) {
            $errors[] = "{$title}: không thấy dòng tiêu đề (cần cột 'Mã môn' và 'Mã lớp học phần').";
            return [];
        }

        $out = [];
        $carry = ['nganh' => null, 'khoa' => null, 'mon' => null];
        foreach ($rows as $r => $line) {
            if ($r <= $headerRow) {
                continue;
            }
            $key = $this->keyOf($line, $cols, $carry);
            $code = $this->text($line[$cols['ma_lop']] ?? null);
            if ($code === null) {
                continue;
            }
            $get = fn (string $k) => isset($cols[$k]) ? $this->text($line[$cols[$k]] ?? null) : null;
            $out[] = $key + [
                'ma_lop' => $code,
                'giang_vien' => $get('giang_vien'),
                'ma_gv' => $get('ma_gv'),
                'nam_hoc' => $get('nam_hoc'),
                'hoc_ky' => $get('hoc_ky'),
                'cell' => $cols['ma_lop'] . $r,
                'row' => $r,
                'cells' => array_map(fn ($c) => $c . $r, $cols),
            ];
        }

        return $out;
    }

    /**
     * Tìm dòng tiêu đề trong 15 dòng đầu: dòng đầu tiên có đủ các cột bắt buộc.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int,string> $required
     * @return array{0: ?int, 1: array<string,string>, 2: array<string,string>} dòng · khóa → cột · cột lạ → nhãn
     */
    private function header(array $rows, array $required): array
    {
        ksort($rows);
        $seen = 0;
        foreach ($rows as $r => $line) {
            if (++$seen > 15) {
                break;
            }
            $cols = [];
            $extra = [];
            foreach ($line as $col => $v) {
                $label = $this->text($v);
                if ($label === null) {
                    continue;
                }
                $slug = ClassSheetReader::slug($label);
                $hit = null;
                foreach (self::COLUMNS as $key => $aliases) {
                    if (isset($cols[$key])) {
                        continue;
                    }
                    foreach ($aliases as $alias) {
                        if ($slug === $alias || ($key === 'trong_so' && str_starts_with($slug, 'trong_so'))) {
                            $hit = $key;
                            break 2;
                        }
                    }
                }
                if ($hit !== null) {
                    $cols[$hit] = (string) $col;
                } else {
                    $extra[(string) $col] = $label;
                }
            }
            if (array_diff($required, array_keys($cols)) === []) {
                return [$r, $cols, $extra];
            }
        }

        return [null, [], []];
    }

    /**
     * Mã ngành · khóa · môn của một dòng; ô trống lấy theo dòng trên.
     *
     * @param array<string,mixed> $line
     * @param array<string,string> $cols
     * @param array{nganh: ?string, khoa: ?string, mon: ?string} $carry
     * @return array{nganh: ?string, khoa: ?string, mon: ?string}
     */
    private function keyOf(array $line, array $cols, array &$carry): array
    {
        foreach (['nganh', 'khoa', 'mon'] as $k) {
            $v = isset($cols[$k]) ? $this->text($line[$cols[$k]] ?? null) : null;
            if ($v === null) {
                continue;
            }
            // Sang ngành khác thì khóa, môn của dòng trên không còn đúng nữa.
            if ($k === 'nganh' && $carry['nganh'] !== null && $carry['nganh'] !== $v) {
                $carry['khoa'] = $carry['mon'] = null;
            }
            $carry[$k] = $v;
        }

        return $carry;
    }

    private function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
