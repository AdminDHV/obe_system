<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CloPiMapping;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseOutcomeLevel;
use App\Models\CourseSyllabus;
use App\Models\Plo;
use App\Models\Program;
use App\Models\Student;
use App\Services\OBE\CloMatrixService;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * File mẫu PHIẾU LỚP HỌC PHẦN — dựng theo đúng bố cục khách vẽ ở buổi họp lần 1.
 *
 * Chọn ngành / khóa / môn thì phiếu được ĐIỀN SẴN: thông tin môn, các cột PLO/PI (theo ma trận CTĐT nếu khóa đã khai), CLO và
 * bài đánh giá đã có của môn, danh sách sinh viên của khóa. Trường chỉ còn điền phần riêng của lớp
 * (mã lớp, giảng viên, câu hỏi, điểm). Không chọn gì thì ra bản trống có dòng ví dụ.
 *
 * Tải file mẫu KHÔNG được ghi gì vào CSDL — chỉ đọc (vì vậy không dùng SyllabusVersionResolver,
 * vốn tạo đề cương khi chưa có).
 *
 * Bố cục phải khớp ClassSheetReader (đọc theo nhãn ở cột A).
 */
final class ClassSheetTemplate
{
    private const QUESTION_TYPES = 'Chuyên cần,Trắc nghiệm,Tự luận,Vấn đáp,Thuyết trình,Tiểu luận,Thực hành';
    private const FIRST_QUESTION_COL = 4; // D
    private const QUESTION_COLS = 30;     // D..AG
    private const STUDENT_ROWS_BLANK = 40;

    public function build(?int $schoolId = null, ?int $programId = null, ?int $cohortId = null, ?int $courseId = null): Spreadsheet
    {
        $program = $programId && $schoolId ? Program::where('school_id', $schoolId)->find($programId) : null;
        $cohort = $program && $cohortId ? Cohort::where('program_id', $program->id)->find($cohortId) : null;
        $course = $courseId && $schoolId ? Course::where('school_id', $schoolId)->find($courseId) : null;

        $syllabus = $course ? $this->existingSyllabus($course, $cohort) : null;
        // Khóa đã khai ma trận CTĐT ⇒ chỉ ra cột PLO/PI mà CTĐT ghi môn này đóng góp: giảng viên không phải
        // dò cả chục cột, và phiếu nối ra ngoài các cột này sẽ bị chặn khi nhập.
        $targets = $program ? ($this->ctdtTargets($cohort, $course) ?: $this->targetsOf($program)) : ['PLO1', 'PLO2', 'PI3.1'];
        $clos = $syllabus ? $this->closOf($syllabus) : [
            ['CLO1', 'Phát triển được vấn đề nghiên cứu', ['PLO1' => 'R']],
            ['CLO2', 'Đánh giá được các lý thuyết và nghiên cứu trước', ['PLO1' => 'R']],
            ['CLO3', 'Có ý thức tuân thủ đạo đức nghiên cứu', ['PLO2' => 'R', 'PI3.1' => 'M']],
        ];
        $assessments = $syllabus ? $this->assessmentsOf($syllabus) : [
            ['Chuyên cần', 'Quá trình', 10],
            ['Giữa kỳ 1', 'Quá trình', 20],
            ['Giữa kỳ 2', 'Quá trình', 20],
            ['Cuối kỳ', 'Cuối kỳ', 50],
        ];
        $students = ($program && $cohort)
            ? Student::where('program_id', $program->id)->where('cohort_id', $cohort->id)
                ->orderBy('student_code')->get(['student_code', 'full_name'])
                ->map(fn ($s) => [$s->student_code, $s->full_name])->all()
            : [['SV0001', 'Nguyễn Văn An'], ['SV0002', 'Trần Thị Bình']];

        return $this->compose($program, $cohort, $course, $syllabus, $targets, $clos, $assessments, $students);
    }

    /**
     * Phiếu của ĐÚNG MỘT LỚP — giáo viên tải từ trang lớp của mình. Thông tin lớp, khai báo của môn và
     * danh sách học viên của lớp đã điền sẵn; giáo viên chỉ còn câu hỏi và điểm.
     */
    public function forClass(CourseClass $class): Spreadsheet
    {
        $class->loadMissing(['syllabus.course', 'cohort.program', 'lecturer']);
        $syllabus = $class->syllabus;
        $course = $syllabus?->course;
        $cohort = $class->cohort;
        $program = $cohort?->program;

        $targets = $program ? ($this->ctdtTargets($cohort, $course) ?: $this->targetsOf($program)) : [];
        $students = $class->students()->orderBy('student_code')->get(['students.student_code', 'students.full_name'])
            ->map(fn ($s) => [$s->student_code, $s->full_name])->all();
        if ($students === [] && $program && $cohort) {
            $students = Student::where('program_id', $program->id)->where('cohort_id', $cohort->id)
                ->orderBy('student_code')->get(['student_code', 'full_name'])
                ->map(fn ($s) => [$s->student_code, $s->full_name])->all();
        }

        return $this->compose(
            $program, $cohort, $course, $syllabus, $targets,
            $syllabus ? $this->closOf($syllabus) : [],
            $syllabus ? $this->assessmentsOf($syllabus) : [],
            $students,
            [
                'ma_lop' => $class->class_code,
                'giang_vien' => $class->lecturer?->full_name,
                'nam_hoc' => $class->academic_year,
                'hoc_ky' => $class->semester === null ? null : (string) $class->semester,
            ],
        );
    }

    /**
     * @param array<int,string> $targets
     * @param array<int,array{0:string,1:?string,2:array<string,string>}> $clos
     * @param array<int,array{0:string,1:string,2:float|int|null}> $assessments
     * @param array<int,array{0:string,1:?string}> $students
     * @param array<string,?string> $lop
     */
    private function compose(?Program $program, ?Cohort $cohort, ?Course $course, ?CourseSyllabus $syllabus, array $targets, array $clos, array $assessments, array $students, array $lop = []): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        $this->infoSheet($book, $program, $cohort, $course, $targets, $clos, $assessments, $lop, $syllabus?->frame_locked_at !== null);

        $example = $syllabus === null;
        foreach ($assessments as $i => [$name]) {
            // Chuyên cần là một bài đánh giá có điểm như mọi bài (khách khai "Chuyên cần 10%" trong khối Đánh giá),
            // thường chỉ một cột điểm thang 10 — điền sẵn cột đó, giảng viên chỉ còn ghi CLO và điểm.
            $questions = $example
                ? $this->exampleQuestions($i)
                : (str_contains(\App\Services\Import\ClassSheetReader::slug($name), 'chuyen_can') ? [['Chuyên cần', 'Chuyên cần', 10, null]] : []);
            $this->assessmentSheet($book, $name, $students, $questions);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ── Sheet KhaiBao ──────────────────────────────────────────────────────────────────────────

    /**
     * @param array<int,string> $targets
     * @param array<int,array{0:string,1:?string,2:array<string,string>}> $clos
     * @param array<int,array{0:string,1:string,2:float|int|null}> $assessments
     */
    private function infoSheet(Spreadsheet $book, ?Program $program, ?Cohort $cohort, ?Course $course, array $targets, array $clos, array $assessments, array $lop = [], bool $theoKhung = false): void
    {
        $ws = new Worksheet($book, 'KhaiBao');
        $book->addSheet($ws);

        $ws->setCellValue('A1', 'PHIẾU LỚP HỌC PHẦN');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', $theoKhung
            ? 'Môn này đã dựng khung: CLO, ma trận và bài đánh giá bên dưới do kỹ thuật của trường khai, sửa ở đây không có tác dụng. Chỉ cần điền câu hỏi và điểm ở sheet từng bài. Vắng thi ghi VT.'
            : 'Điền các ô tô vàng. Mỗi lớp học phần một phiếu. Mỗi bài đánh giá một sheet riêng, tên sheet trùng tên bài. Vắng thi ghi VT.');
        $ws->getStyle('A2')->getFont()->setItalic(true);

        $info = [
            ['Mã môn học', $course?->code],
            ['Tên môn học', $course?->name],
            ['Mã ngành', $program?->code],
            ['Khóa', $cohort?->name],
            ['Mã lớp học phần', $lop['ma_lop'] ?? null],
            ['Giảng viên giảng dạy', $lop['giang_vien'] ?? null],
            ['Năm học', $lop['nam_hoc'] ?? ($cohort ? $cohort->start_year . '-' . ($cohort->start_year + 1) : null)],
            ['Học kỳ', $lop['hoc_ky'] ?? null],
        ];
        $r = 3;
        foreach ($info as [$label, $value]) {
            $ws->setCellValue("A{$r}", $label);
            $ws->setCellValueExplicit("B{$r}", $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $this->fill($ws, "B{$r}");
            $r++;
        }

        // Chuẩn đầu ra + ma trận CLO → PLO/PI (đúng dạng bảng trong đề cương).
        // Gợi ý nằm ở dòng TRÊN tiêu đề — đặt cùng dòng tiêu đề thì bộ đọc tưởng đó là một mã PLO/PI.
        $ws->setCellValue("C{$r}", 'Ghi I, R, M hoặc A vào ô giao giữa CLO và PLO/PI mà CLO đó đóng góp');
        $ws->getStyle("C{$r}")->getFont()->setItalic(true);
        $r++;
        $cloHeader = $r;
        $ws->setCellValue("A{$r}", 'Chuẩn đầu ra');
        $ws->setCellValue("B{$r}", 'Nội dung');
        foreach ($targets as $i => $code) {
            $ws->setCellValue($this->col(3 + $i) . $r, $code);
        }
        $this->header($ws, "A{$r}:" . $this->col(max(3, 2 + count($targets))) . $r);

        foreach ($clos as [$code, $desc, $map]) {
            $r++;
            $ws->setCellValue("A{$r}", $code);
            $ws->setCellValue("B{$r}", $desc);
            foreach ($targets as $i => $t) {
                if (isset($map[$t])) {
                    $ws->setCellValue($this->col(3 + $i) . $r, $map[$t]);
                }
            }
        }
        // Chừa sẵn dòng trống cho CLO: môn chưa có CLO mà khối Đánh giá nằm sát tiêu đề thì trường
        // gõ CLO vào là đè mất tiêu đề "Đánh giá" (test đi hết vòng đã bắt đúng lỗi này).
        $lastClo = $cloHeader + max(count($clos), 12);
        $this->fill($ws, 'A' . ($cloHeader + 1) . ':B' . $lastClo);
        if ($targets !== []) {
            $range = 'C' . ($cloHeader + 1) . ':' . $this->col(2 + count($targets)) . $lastClo;
            $this->fill($ws, $range);
            $this->listValidation($ws, $range, 'I,R,M,A');
        }

        // Đánh giá
        $r = $lastClo + 2;
        $aHeader = $r;
        $ws->setCellValue("A{$r}", 'Đánh giá');
        $ws->setCellValue("B{$r}", 'Loại');
        $ws->setCellValue("C{$r}", 'Trọng số (%)');
        $this->header($ws, "A{$r}:C{$r}");
        foreach ($assessments as [$name, $type, $weight]) {
            $r++;
            $ws->setCellValue("A{$r}", $name);
            $ws->setCellValue("B{$r}", $type);
            $ws->setCellValue("C{$r}", $weight);
        }
        $this->fill($ws, 'A' . ($aHeader + 1) . ':C' . ($aHeader + 8));
        $this->listValidation($ws, 'B' . ($aHeader + 1) . ':B' . ($aHeader + 8), 'Quá trình,Cuối kỳ');
        $ws->setCellValue("D{$aHeader}", 'Tổng (phải = 100):');
        $ws->setCellValue("E{$aHeader}", '=SUM(C' . ($aHeader + 1) . ':C' . ($aHeader + 8) . ')');
        $ws->getStyle("D{$aHeader}:E{$aHeader}")->getFont()->setBold(true);

        $ws->getColumnDimension('A')->setWidth(24);
        $ws->getColumnDimension('B')->setWidth(48);
        foreach (range(3, 3 + max(count($targets), 1)) as $c) {
            $ws->getColumnDimension($this->col($c))->setWidth(10);
        }
    }

    // ── Sheet từng bài đánh giá ────────────────────────────────────────────────────────────────

    /**
     * @param array<int,array{0:string,1:?string}> $students
     * @param array<int,array{0:string,1:string,2:float,3:string}> $questions
     */
    private function assessmentSheet(Spreadsheet $book, string $name, array $students, array $questions): void
    {
        $title = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $name), 0, 31);
        $ws = new Worksheet($book, $title);
        $book->addSheet($ws);

        $lastQ = $this->col(self::FIRST_QUESTION_COL + self::QUESTION_COLS - 1);

        $ws->setCellValue('A1', 'Bài đánh giá');
        $ws->setCellValue('B1', $name);
        $ws->getStyle('A1:B1')->getFont()->setBold(true);

        $ws->setCellValue('A2', 'Hình thức');
        $ws->setCellValue('A3', 'Điểm tối đa');
        $ws->setCellValue('B3', 'Tổng (phải = 10):');
        $ws->setCellValue('C3', "=SUM(D3:{$lastQ}3)");
        $ws->setCellValue('A4', 'CLO cần đo');
        $ws->setCellValue('A5', 'PLO/PI cần đo');
        $ws->setCellValue('B5', 'Để trống — hệ thống tự lấy từ ma trận ở sheet KhaiBao');
        $ws->getStyle('B5')->getFont()->setItalic(true);
        $ws->getStyle('A2:A5')->getFont()->setBold(true);

        $ws->setCellValue('A7', 'STT');
        $ws->setCellValue('B7', 'MSSV');
        $ws->setCellValue('C7', 'Họ và tên');

        $qCount = max(count($questions), 3);
        for ($i = 0; $i < $qCount; $i++) {
            $c = $this->col(self::FIRST_QUESTION_COL + $i);
            [$label, $type, $max, $clo] = $questions[$i] ?? ['Câu ' . ($i + 1), null, null, null];
            $ws->setCellValue("{$c}7", $label);
            $ws->setCellValue("{$c}2", $type);
            $ws->setCellValue("{$c}3", $max);
            $ws->setCellValue("{$c}4", $clo);
        }
        $this->header($ws, 'A7:' . $this->col(self::FIRST_QUESTION_COL + $qCount - 1) . '7');
        $this->fill($ws, "D2:{$lastQ}4");
        $this->listValidation($ws, "D2:{$lastQ}2", self::QUESTION_TYPES);

        $rows = max(count($students), 1) + ($students === [] ? self::STUDENT_ROWS_BLANK : 0);
        foreach ($students as $i => [$code, $full]) {
            $r = 8 + $i;
            $ws->setCellValue("A{$r}", $i + 1);
            $ws->setCellValueExplicit("B{$r}", $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $ws->setCellValue("C{$r}", $full);
        }
        $lastRow = 8 + max($rows, count($students)) + 20;

        // Ô điểm không được lớn hơn điểm tối đa của câu (dòng 3) — khách dặn đúng điều này. Vắng thi
        // ghi VT (trường dặn không để trống), nên luật phải nhận cả VT, không chỉ số.
        $scoreRange = "D8:{$lastQ}{$lastRow}";
        $this->fill($ws, $scoreRange);
        $dv = $ws->getCell('D8')->getDataValidation();
        $dv->setType(DataValidation::TYPE_CUSTOM)
            ->setFormula1('OR(UPPER(TRIM(D8))="VT",AND(ISNUMBER(D8),D8>=0,D8<=D$3))')
            ->setAllowBlank(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Điểm không hợp lệ')
            ->setError('Ghi điểm từ 0 tới điểm tối đa ở dòng 3. Học viên vắng thi thì ghi VT.');
        $dv->setSqref($scoreRange);

        $ws->freezePane('D8');
        $ws->getColumnDimension('A')->setWidth(16);
        $ws->getColumnDimension('B')->setWidth(16);
        $ws->getColumnDimension('C')->setWidth(28);
    }

    /** @return array<int,array{0:string,1:string,2:float,3:string}> */
    private function exampleQuestions(int $i): array
    {
        return match ($i) {
            0 => [['Chuyên cần', 'Chuyên cần', 10, 'CLO3']],
            3 => [['Câu 1', 'Tự luận', 5, 'CLO1'], ['Câu 2', 'Tự luận', 5, 'CLO2']],
            default => [['Câu 1', 'Tự luận', 4, 'CLO1'], ['Câu 2', 'Tự luận', 3, 'CLO2'], ['Câu 3-15', 'Trắc nghiệm', 3, 'CLO3']],
        };
    }

    // ── Đọc dữ liệu có sẵn (chỉ đọc) ───────────────────────────────────────────────────────────

    private function existingSyllabus(Course $course, ?Cohort $cohort): ?CourseSyllabus
    {
        return ($cohort ? CourseSyllabus::where('course_id', $course->id)->where('version', $cohort->name)->first() : null)
            ?? CourseSyllabus::where('course_id', $course->id)->where('version', '1.0')->first();
    }

    /** Cột PLO/PI theo ma trận CTĐT của (khóa, môn); chưa khai thì rỗng. @return array<int,string> */
    public function ctdtTargets(?Cohort $cohort, ?Course $course): array
    {
        if (! $cohort || ! $course) {
            return [];
        }

        return CourseOutcomeLevel::where('cohort_id', $cohort->id)->where('course_id', $course->id)
            ->with('pi.plo:id,code')->get()
            ->sortBy(fn (CourseOutcomeLevel $r) => sprintf('%010d-%010d', $r->pi?->plo_id ?? 0, $r->pi_id))
            ->map(fn (CourseOutcomeLevel $r) => CloMatrixService::targetCode($r->pi))
            ->unique()->values()->all();
    }

    /** PI thật của ngành; PLO chưa chia PI thì ghi mã PLO (nối thẳng). @return array<int,string> */
    public function targetsOf(Program $program): array
    {
        $out = [];
        foreach (Plo::where('program_id', $program->id)->with('pis')->orderBy('id')->get() as $plo) {
            $real = $plo->pis->where('is_dummy', false);
            if ($real->isEmpty()) {
                $out[] = $plo->code;
            } else {
                foreach ($real->sortBy('id') as $pi) {
                    $out[] = $pi->code;
                }
            }
        }

        return $out;
    }

    /** @return array<int,array{0:string,1:?string,2:array<string,string>}> */
    public function closOf(CourseSyllabus $syllabus): array
    {
        $clos = Clo::where('syllabus_id', $syllabus->id)->orderBy('id')->get();
        $maps = CloPiMapping::whereIn('clo_id', $clos->pluck('id'))->with('pi.plo')->get()->groupBy('clo_id');

        return $clos->map(fn (Clo $c) => [
            $c->code,
            $c->description,
            ($maps[$c->id] ?? collect())->mapWithKeys(fn ($m) => [
                ($m->pi?->is_dummy ? $m->pi?->plo?->code : $m->pi?->code) ?? '?' => $m->mapping_level,
            ])->all(),
        ])->all();
    }

    /** @return array<int,array{0:string,1:string,2:float}> */
    public function assessmentsOf(CourseSyllabus $syllabus): array
    {
        $rows = Assessment::where('syllabus_id', $syllabus->id)->orderBy('id')->get()
            ->map(fn (Assessment $a) => [$a->name, $a->assessment_category === 'final' ? 'Cuối kỳ' : 'Quá trình', (float) $a->weight_percent])
            ->all();

        return $rows !== [] ? $rows : [['Chuyên cần', 'Quá trình', 10], ['Giữa kỳ 1', 'Quá trình', 40], ['Cuối kỳ', 'Cuối kỳ', 50]];
    }

    // ── Trình bày ──────────────────────────────────────────────────────────────────────────────

    public function col(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    public function fill(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF7CC');
    }

    public function header(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getFont()->setBold(true);
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDE7F3');
    }

    public function listValidation(Worksheet $ws, string $range, string $values): void
    {
        [$first] = explode(':', $range);
        $dv = $ws->getCell($first)->getDataValidation();
        $dv->setType(DataValidation::TYPE_LIST)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setFormula1('"' . $values . '"');
        $dv->setSqref($range);
    }
}
