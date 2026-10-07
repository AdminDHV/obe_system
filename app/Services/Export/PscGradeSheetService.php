<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Assessment;
use App\Models\CourseClass;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Services\Import\ClassSheetReader;
use App\Services\OBE\CourseGradeFormula;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * ĐIỀN ĐIỂM VÀO FILE BẢNG ĐIỂM CỦA PHẦN MỀM QUẢN LÝ ĐÀO TẠO (PSC).
 *
 * Trường vẫn phải nhập điểm vào phần mềm đào tạo, mà file bảng điểm của phần mềm đó có những cột
 * hệ thống này không giữ (lớp hành chính, tình trạng, nợ phí, ngày sinh) và mã cột riêng của nó
 * ("K1#1", "_ASS101#1"). Nên hệ thống KHÔNG tự sinh file mới: giảng viên tải đúng file mẫu từ phần
 * mềm đào tạo lên, hệ thống dò cột Mã SV rồi điền điểm vào các cột điểm, giữ nguyên mọi thứ còn lại.
 *
 * Cột nào ứng với bài nào thì hệ thống đoán trước theo nhãn ("Thi", "LT", "Cuối kỳ" ⇒ bài cuối kỳ),
 * nhưng người dùng vẫn xác nhận lại trước khi điền — đoán sai mà điền thẳng thì sai điểm cả lớp.
 */
final class PscGradeSheetService
{
    /** Tiêu đề cột thông tin sinh viên — không phải cột điểm. */
    private const INFO_HEADERS = [
        'stt', 'ma_sv', 'mssv', 'ma_sinh_vien', 'ho_la_ten_lot', 'ho_va_ten_lot', 'ho_ten', 'ho_va_ten',
        'ten', 'ngay_sinh', 'lop', 'tinh_trang', 'no_phi', 'ghi_chu', 'diem_chu', 'ket_qua',
    ];

    /**
     * Đọc cấu trúc file: dòng tiêu đề, cột mã sinh viên, các cột điểm kèm nhãn nhóm phía trên.
     *
     * @return array{header_row:int,code_col:string,columns:array<int,array{col:string,label:string,group:string}>,students:int}
     */
    public function inspect(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, true);

        [$headerRow, $codeCol] = $this->locateHeader($rows);

        $columns = [];
        foreach ($rows[$headerRow] as $col => $value) {
            $label = trim((string) $value);
            if ($label === '' || in_array(ClassSheetReader::slug($label), self::INFO_HEADERS, true)) {
                continue;
            }
            if ($this->colIndex($col) < $this->colIndex($codeCol)) {
                continue;
            }
            $columns[] = ['col' => $col, 'label' => $label, 'group' => $this->groupLabel($rows, $headerRow, $col)];
        }

        return [
            'header_row' => $headerRow,
            'code_col' => $codeCol,
            'columns' => $columns,
            'students' => $this->studentRows($rows, $headerRow, $codeCol)->count(),
        ];
    }

    /**
     * Ghép sẵn mỗi cột điểm với một bài đánh giá của lớp.
     *
     * Quy tắc: cột nói "thi"/"cuối kỳ"/"LT" đi với bài cuối kỳ, còn lại đi với bài quá trình, theo
     * đúng thứ tự khai trong phiếu. Một cột mà nhiều bài cùng nhóm ⇒ điền điểm gộp của nhóm đó
     * (trung bình theo trọng số), vì phần mềm đào tạo chỉ nhận một con số.
     *
     * @param array<int,array{col:string,label:string,group:string}> $columns
     * @return array<string,string> col → assessment id, 'qua_trinh', 'cuoi_ky' hoặc '' (bỏ trống)
     */
    public function suggestMapping(array $columns, CourseClass $class): array
    {
        $assessments = Assessment::where('syllabus_id', $class->syllabus_id)->orderBy('id')->get();
        $final = $assessments->where('assessment_category', 'final')->values();
        $process = $assessments->where('assessment_category', '!=', 'final')->values();

        $finalCols = [];
        $processCols = [];
        foreach ($columns as $c) {
            if ($this->looksFinal($c)) {
                $finalCols[] = $c['col'];
            } else {
                $processCols[] = $c['col'];
            }
        }

        $map = [];
        foreach ([[$finalCols, $final, 'cuoi_ky'], [$processCols, $process, 'qua_trinh']] as [$cols, $items, $gop]) {
            if ($cols === []) {
                continue;
            }
            if (count($cols) === 1 && $items->count() > 1) {
                $map[$cols[0]] = $gop;

                continue;
            }
            foreach ($cols as $i => $col) {
                $map[$col] = isset($items[$i]) ? (string) $items[$i]->id : '';
            }
        }

        return $map;
    }

    /**
     * Điền điểm vào file rồi ghi ra `$out`.
     *
     * @param array<string,string> $mapping col → assessment id | 'qua_trinh' | 'cuoi_ky' | ''
     * @return array{filled:int,missing:array<int,string>,columns:array<string,string>}
     *         missing = mã sinh viên có trong file nhưng không có điểm trong hệ thống
     */
    public function fill(string $path, CourseClass $class, array $mapping, string $out): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, true);

        [$headerRow, $codeCol] = $this->locateHeader($rows);

        $assessments = Assessment::where('syllabus_id', $class->syllabus_id)->get();
        $grades = StudentGrade::where('class_id', $class->id)->get(['student_id', 'assessment_id', 'score', 'is_absent']);

        $studentIdByCode = Student::where('school_id', $class->school_id)
            ->whereIn('id', $grades->pluck('student_id')->unique())
            ->pluck('id', 'student_code');

        $filled = 0;
        $missing = [];

        foreach ($this->studentRows($rows, $headerRow, $codeCol) as $rowNum => $code) {
            $studentId = $studentIdByCode[$code] ?? null;
            if ($studentId === null) {
                $missing[] = $code;

                continue;
            }

            $ofStudent = $grades->where('student_id', $studentId);
            $wrote = false;

            foreach ($mapping as $col => $target) {
                $value = $this->valueFor((string) $target, $ofStudent, $assessments);
                if ($value === null) {
                    // Vắng thi thì ghi VT như phần mềm đào tạo vẫn ghi — không để trống, không ghi 0.
                    if ($this->vangThi((string) $target, $ofStudent, $assessments)) {
                        $sheet->setCellValueExplicit($col . $rowNum, 'VT', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $wrote = true;
                    }
                    continue;
                }
                $sheet->setCellValue($col . $rowNum, round($value, 1));
                $wrote = true;
            }

            $filled += $wrote ? 1 : 0;
        }

        IOFactory::createWriter($book, 'Xlsx')->save($out);

        return ['filled' => $filled, 'missing' => $missing, 'columns' => $mapping];
    }

    /**
     * @param \Illuminate\Support\Collection<int,StudentGrade> $ofStudent
     * @param \Illuminate\Support\Collection<int,Assessment> $assessments
     */
    private function valueFor(string $target, $ofStudent, $assessments): ?float
    {
        if ($target === '') {
            return null;
        }

        if ($target === 'qua_trinh' || $target === 'cuoi_ky') {
            $group = $target === 'cuoi_ky'
                ? $assessments->where('assessment_category', 'final')
                : $assessments->where('assessment_category', '!=', 'final');

            $rows = $group->map(fn (Assessment $a) => [
                'weight' => (float) ($a->weight_percent ?? 0),
                'category' => (string) $a->assessment_category,
                'score' => $ofStudent->firstWhere('assessment_id', $a->id)?->score,
            ])->filter(fn ($r) => $r['score'] !== null)
                ->map(fn ($r) => ['weight' => $r['weight'], 'category' => $r['category'], 'score' => (float) $r['score']])
                ->values()->all();

            if ($rows === []) {
                return null;
            }

            // Trong cùng một nhóm, trung bình theo trọng số của chính các bài trong nhóm.
            $tong = array_sum(array_column($rows, 'weight'));
            if ($tong <= 0) {
                return array_sum(array_column($rows, 'score')) / count($rows);
            }

            return array_sum(array_map(fn ($r) => $r['score'] * $r['weight'], $rows)) / $tong;
        }

        $score = $ofStudent->firstWhere('assessment_id', (int) $target)?->score;

        return $score === null ? null : (float) $score;
    }

    /**
     * Học viên vắng (VT) ở bài — hoặc ở mọi bài của nhóm quá trình / cuối kỳ — mà cột này lấy điểm.
     *
     * @param \Illuminate\Support\Collection<int,StudentGrade> $ofStudent
     * @param \Illuminate\Support\Collection<int,Assessment> $assessments
     */
    private function vangThi(string $target, $ofStudent, $assessments): bool
    {
        if ($target === '') {
            return false;
        }

        $ids = match ($target) {
            'cuoi_ky' => $assessments->where('assessment_category', 'final')->pluck('id'),
            'qua_trinh' => $assessments->where('assessment_category', '!=', 'final')->pluck('id'),
            default => collect([(int) $target]),
        };
        $rows = $ofStudent->whereIn('assessment_id', $ids);

        return $rows->contains(fn (StudentGrade $g) => $g->is_absent && $g->score === null)
            && ! $rows->contains(fn (StudentGrade $g) => $g->score !== null);
    }

    /**
     * Điểm tổng kết môn — dùng khi file có cột "Điểm tổng kết".
     *
     * @param \Illuminate\Support\Collection<int,StudentGrade> $ofStudent
     * @param \Illuminate\Support\Collection<int,Assessment> $assessments
     */
    public function courseMark($ofStudent, $assessments): ?float
    {
        return CourseGradeFormula::fromAssessments($assessments->map(fn (Assessment $a) => [
            'weight' => (float) ($a->weight_percent ?? 0),
            'category' => (string) $a->assessment_category,
            'score' => $this->scoreOf($ofStudent, $a),
        ])->values()->all());
    }

    /** @param \Illuminate\Support\Collection<int,StudentGrade> $ofStudent */
    private function scoreOf($ofStudent, Assessment $a): ?float
    {
        $score = $ofStudent->firstWhere('assessment_id', $a->id)?->score;

        return $score === null ? null : (float) $score;
    }

    /**
     * Dòng tiêu đề và cột mã sinh viên. File của phần mềm đào tạo có mấy dòng gộp ở trên, nên
     * không thể chốt cứng "tiêu đề ở dòng 1".
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{0:int,1:string}
     */
    private function locateHeader(array $rows): array
    {
        foreach ($rows as $rowNum => $row) {
            foreach ($row as $col => $value) {
                if (in_array(ClassSheetReader::slug((string) $value), ['ma_sv', 'mssv', 'ma_sinh_vien'], true)) {
                    return [(int) $rowNum, (string) $col];
                }
            }
        }

        throw new \RuntimeException('Không tìm thấy cột "Mã SV" trong file. Hãy tải đúng file bảng điểm xuất từ phần mềm quản lý đào tạo.');
    }

    /**
     * Mã sinh viên theo từng dòng dữ liệu.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return \Illuminate\Support\Collection<int,string>
     */
    private function studentRows(array $rows, int $headerRow, string $codeCol): \Illuminate\Support\Collection
    {
        return collect($rows)
            ->filter(fn ($row, $num) => $num > $headerRow && trim((string) ($row[$codeCol] ?? '')) !== '')
            ->map(fn ($row) => trim((string) $row[$codeCol]));
    }

    /** Nhãn nhóm của một cột điểm: ô gần nhất phía trên có chữ (các dòng gộp "Điểm QT (40%)"…). */
    private function groupLabel(array $rows, int $headerRow, string $col): string
    {
        $parts = [];
        for ($r = max(1, $headerRow - 3); $r < $headerRow; $r++) {
            $value = trim((string) ($rows[$r][$col] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(' · ', $parts);
    }

    /** @param array{col:string,label:string,group:string} $c */
    private function looksFinal(array $c): bool
    {
        $slug = ClassSheetReader::slug($c['group'] . ' ' . $c['label']);

        foreach (['cuoi_ky', 'thi', 'ck', 'lt', 'ass'] as $dau) {
            if (str_contains($slug, $dau)) {
                return true;
            }
        }

        return false;
    }

    private function colIndex(string $col): int
    {
        return Coordinate::columnIndexFromString($col);
    }
}
