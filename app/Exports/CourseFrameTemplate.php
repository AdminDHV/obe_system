<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Assessment;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseOutcomeLevel;
use App\Models\CourseSyllabus;
use App\Models\Program;
use App\Services\Import\CourseFrameReader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * File mẫu KHUNG HỌC PHẦN THEO ĐỢT — bố cục khớp `CourseFrameReader`.
 *
 * Chọn ngành và khóa thì điền sẵn mọi môn trong ma trận CTĐT của khóa (chưa khai ma trận thì mọi
 * môn của ngành): cột PLO/PI của ngành, CLO và bài đánh giá đã có, lớp đã có. Môn chưa có bài đánh
 * giá được điền sẵn bộ chuẩn chuyên cần · giữa kỳ · cuối kỳ để kỹ thuật chỉ sửa trọng số.
 *
 * Chỉ đọc CSDL, không ghi gì (không dùng SyllabusVersionResolver::forCourse vì hàm đó tạo đề cương).
 */
final class CourseFrameTemplate
{
    /** Bộ bài chuẩn cho môn chưa khai — QT 40 (chuyên cần 10 + giữa kỳ 30), thi 60, như bảng điểm của trường. */
    private const DEFAULT_ASSESSMENTS = [
        ['Chuyên cần', 'Quá trình', 10],
        ['Giữa kỳ', 'Quá trình', 30],
        ['Cuối kỳ', 'Cuối kỳ', 60],
    ];

    private ClassSheetTemplate $ui;

    public function __construct()
    {
        $this->ui = new ClassSheetTemplate();
    }

    public function build(?int $schoolId = null, ?int $programId = null, ?int $cohortId = null): Spreadsheet
    {
        $program = $programId && $schoolId ? Program::where('school_id', $schoolId)->find($programId) : null;
        $cohort = $program && $cohortId ? Cohort::where('program_id', $program->id)->find($cohortId) : null;

        $targets = $program ? $this->ui->targetsOf($program) : ['PLO1', 'PI2.1', 'PI2.2'];
        $courses = $this->coursesOf($program, $cohort);

        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        $this->guideSheet($book, $program, $cohort);
        $this->cloSheet($book, $program, $cohort, $courses, $targets);
        $this->assessmentSheet($book, $program, $cohort, $courses);
        $this->classSheet($book, $program, $cohort, $courses);

        $book->setActiveSheetIndex(1);

        return $book;
    }

    // ── Dữ liệu (chỉ đọc) ──────────────────────────────────────────────────────────────────────

    /** @return array<int,array{course: Course, syllabus: ?CourseSyllabus}> */
    private function coursesOf(?Program $program, ?Cohort $cohort): array
    {
        if (! $program) {
            return [];
        }

        $ids = $cohort ? array_keys(CourseOutcomeLevel::forCohort((int) $cohort->id)) : [];
        $query = $ids !== []
            ? Course::whereIn('id', $ids)
            : $program->courses();

        return $query->orderBy('code')->get()
            ->map(fn (Course $c) => ['course' => $c, 'syllabus' => $this->syllabusOf($c, $cohort)])
            ->all();
    }

    /** Đề cương của môn cho khóa — cùng thứ tự tìm như SyllabusVersionResolver nhưng không tạo mới. */
    private function syllabusOf(Course $course, ?Cohort $cohort): ?CourseSyllabus
    {
        if ($cohort) {
            $own = CourseSyllabus::where('course_id', $course->id)->where('version', $cohort->name)->first();
            if ($own) {
                return $own;
            }
            $used = CourseClass::where('cohort_id', $cohort->id)
                ->whereIn('syllabus_id', CourseSyllabus::where('course_id', $course->id)->select('id'))
                ->value('syllabus_id');
            if ($used) {
                return CourseSyllabus::find($used);
            }
        }

        return CourseSyllabus::where('course_id', $course->id)->where('version', '1.0')->first();
    }

    // ── Các sheet ──────────────────────────────────────────────────────────────────────────────

    private function guideSheet(Spreadsheet $book, ?Program $program, ?Cohort $cohort): void
    {
        $ws = new Worksheet($book, 'HuongDan');
        $book->addSheet($ws);

        $lines = [
            'KHUNG HỌC PHẦN THEO ĐỢT' . ($program ? " — {$program->code}" : '') . ($cohort ? " · {$cohort->name}" : ''),
            '',
            'Kỹ thuật của trường điền file này một lần cho cả đợt. Giáo viên sau đó chỉ tải phiếu của lớp mình, điền câu hỏi và điểm, rồi nộp.',
            '',
            'Sheet CLO: mỗi CLO một dòng. Ghi I, R, M hoặc A vào ô giao giữa CLO và PLO/PI mà CLO đó đóng góp.',
            'Sheet DanhGia: các bài đánh giá của môn kèm trọng số. Tổng trọng số của một môn phải đúng 100.',
            'Sheet LopHocPhan: mỗi lớp học phần một dòng, kèm giảng viên, năm học, học kỳ.',
            '',
            'Mã ngành, Khóa, Mã môn để trống thì lấy theo dòng trên. Môn chưa khai CLO thì hệ thống bỏ qua, dù có sẵn dòng bài đánh giá mẫu.',
            'Một đợt tách được nhiều file, ví dụ mỗi khoa một file. File chỉ có sheet LopHocPhan dùng để thêm lớp cho môn đã dựng khung.',
            'Tải lại file đã sửa là cập nhật theo mã, không tạo bản trùng. CLO hay bài đã có điểm thì không bỏ khỏi khung được.',
        ];
        foreach ($lines as $i => $text) {
            $ws->setCellValue('A' . ($i + 1), $text);
        }
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->getColumnDimension('A')->setWidth(130);
    }

    /**
     * @param array<int,array{course: Course, syllabus: ?CourseSyllabus}> $courses
     * @param array<int,string> $targets
     */
    private function cloSheet(Spreadsheet $book, ?Program $program, ?Cohort $cohort, array $courses, array $targets): void
    {
        $ws = new Worksheet($book, CourseFrameReader::CLO_SHEET);
        $book->addSheet($ws);

        $head = array_merge(['Mã ngành', 'Khóa', 'Mã môn', 'Tên môn', 'Mã CLO', 'Nội dung CLO'], $targets);
        foreach ($head as $i => $label) {
            $ws->setCellValue($this->ui->col($i + 1) . '1', $label);
        }
        $lastCol = $this->ui->col(count($head));
        $this->ui->header($ws, "A1:{$lastCol}1");

        $rows = [];
        if ($courses === []) {
            $rows[] = ['NGANH01', 'Khóa 2026', 'MON01', 'Môn ví dụ', 'CLO1', 'Vận dụng được kiến thức nền', ['PLO1' => 'R']];
            $rows[] = ['NGANH01', 'Khóa 2026', 'MON01', 'Môn ví dụ', 'CLO2', 'Phân tích được tình huống', ['PI2.1' => 'M']];
        }
        foreach ($courses as ['course' => $course, 'syllabus' => $syllabus]) {
            $clos = $syllabus ? $this->ui->closOf($syllabus) : [];
            if ($clos === []) {
                $rows[] = [$program->code, $cohort?->name, $course->code, $course->name, null, null, []];
                continue;
            }
            foreach ($clos as [$code, $desc, $map]) {
                $rows[] = [$program->code, $cohort?->name, $course->code, $course->name, $code, $desc, $map];
            }
        }

        $r = 1;
        foreach ($rows as [$nganh, $khoa, $mon, $ten, $clo, $desc, $map]) {
            $r++;
            $this->text($ws, "A{$r}", $nganh);
            $this->text($ws, "B{$r}", $khoa);
            $this->text($ws, "C{$r}", $mon);
            $ws->setCellValue("D{$r}", $ten);
            $ws->setCellValue("E{$r}", $clo);
            $ws->setCellValue("F{$r}", $desc);
            foreach ($targets as $i => $t) {
                if (isset($map[$t])) {
                    $ws->setCellValue($this->ui->col(7 + $i) . $r, $map[$t]);
                }
            }
        }

        $last = max($r, 2) + 60;
        $this->ui->fill($ws, "E2:F{$last}");
        if ($targets !== []) {
            $range = 'G2:' . $lastCol . $last;
            $this->ui->fill($ws, $range);
            $this->ui->listValidation($ws, $range, 'I,R,M,A');
        }

        $ws->freezePane('G2');
        foreach (['A' => 12, 'B' => 12, 'C' => 12, 'D' => 30, 'E' => 10, 'F' => 50] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
        foreach (array_keys($targets) as $i) {
            $ws->getColumnDimension($this->ui->col(7 + $i))->setWidth(9);
        }
    }

    /** @param array<int,array{course: Course, syllabus: ?CourseSyllabus}> $courses */
    private function assessmentSheet(Spreadsheet $book, ?Program $program, ?Cohort $cohort, array $courses): void
    {
        $ws = new Worksheet($book, CourseFrameReader::ASSESSMENT_SHEET);
        $book->addSheet($ws);

        foreach (['Mã ngành', 'Khóa', 'Mã môn', 'Tên môn', 'Tên bài', 'Loại', 'Trọng số (%)'] as $i => $label) {
            $ws->setCellValue($this->ui->col($i + 1) . '1', $label);
        }
        $this->ui->header($ws, 'A1:G1');

        $rows = [];
        if ($courses === []) {
            foreach (self::DEFAULT_ASSESSMENTS as [$name, $type, $w]) {
                $rows[] = ['NGANH01', 'Khóa 2026', 'MON01', 'Môn ví dụ', $name, $type, $w];
            }
        }
        foreach ($courses as ['course' => $course, 'syllabus' => $syllabus]) {
            $existing = $syllabus
                ? Assessment::where('syllabus_id', $syllabus->id)->orderBy('id')->get()
                    ->map(fn (Assessment $a) => [$a->name, $a->assessment_category === 'final' ? 'Cuối kỳ' : 'Quá trình', (float) $a->weight_percent])
                    ->all()
                : [];
            foreach ($existing ?: self::DEFAULT_ASSESSMENTS as [$name, $type, $w]) {
                $rows[] = [$program->code, $cohort?->name, $course->code, $course->name, $name, $type, $w];
            }
        }

        $r = 1;
        foreach ($rows as [$nganh, $khoa, $mon, $ten, $name, $type, $w]) {
            $r++;
            $this->text($ws, "A{$r}", $nganh);
            $this->text($ws, "B{$r}", $khoa);
            $this->text($ws, "C{$r}", $mon);
            $ws->setCellValue("D{$r}", $ten);
            $ws->setCellValue("E{$r}", $name);
            $ws->setCellValue("F{$r}", $type);
            $ws->setCellValue("G{$r}", $w);
        }

        $last = max($r, 2) + 60;
        $this->ui->fill($ws, "E2:G{$last}");
        $this->ui->listValidation($ws, "F2:F{$last}", 'Quá trình,Cuối kỳ');

        $ws->freezePane('E2');
        foreach (['A' => 12, 'B' => 12, 'C' => 12, 'D' => 30, 'E' => 20, 'F' => 12, 'G' => 14] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
    }

    /** @param array<int,array{course: Course, syllabus: ?CourseSyllabus}> $courses */
    private function classSheet(Spreadsheet $book, ?Program $program, ?Cohort $cohort, array $courses): void
    {
        $ws = new Worksheet($book, CourseFrameReader::CLASS_SHEET);
        $book->addSheet($ws);

        foreach (['Mã ngành', 'Khóa', 'Mã môn', 'Tên môn', 'Mã lớp học phần', 'Mã giảng viên', 'Giảng viên', 'Năm học', 'Học kỳ'] as $i => $label) {
            $ws->setCellValue($this->ui->col($i + 1) . '1', $label);
        }
        $this->ui->header($ws, 'A1:I1');

        $year = $cohort ? $cohort->start_year . '-' . ($cohort->start_year + 1) : null;
        $rows = [];
        if ($courses === []) {
            $rows[] = ['NGANH01', 'Khóa 2026', 'MON01', 'Môn ví dụ', 'MON01-01', 'GV001', 'Nguyễn Văn A', '2026-2027', '1'];
        }
        foreach ($courses as ['course' => $course, 'syllabus' => $syllabus]) {
            $classes = ($syllabus && $cohort)
                ? CourseClass::with('lecturer:id,full_name,staff_code')->where('syllabus_id', $syllabus->id)->where('cohort_id', $cohort->id)->orderBy('class_code')->get()
                : collect();
            if ($classes->isEmpty()) {
                $rows[] = [$program->code, $cohort?->name, $course->code, $course->name, null, null, null, $year, null];
                continue;
            }
            foreach ($classes as $c) {
                $rows[] = [$program->code, $cohort?->name, $course->code, $course->name, $c->class_code, ClassSheetTemplate::realStaffCode($c->lecturer?->staff_code, $c->school_id), $c->lecturer?->full_name, $c->academic_year, $c->semester === null ? null : (string) $c->semester];
            }
        }

        $r = 1;
        foreach ($rows as $row) {
            $r++;
            foreach ($row as $i => $v) {
                $this->text($ws, $this->ui->col($i + 1) . $r, $v);
            }
        }

        $last = max($r, 2) + 60;
        $this->ui->fill($ws, "E2:I{$last}");

        $ws->freezePane('E2');
        foreach (['A' => 12, 'B' => 12, 'C' => 12, 'D' => 30, 'E' => 18, 'F' => 14, 'G' => 26, 'H' => 12, 'I' => 8] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
    }

    /** Ghi dạng chữ: mã như "0101" hay "2026-2027" không được Excel đổi thành số hoặc ngày. */
    private function text(Worksheet $ws, string $cell, ?string $value): void
    {
        if ($value !== null) {
            $ws->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }
    }
}
