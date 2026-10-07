<?php

declare(strict_types=1);

namespace App\Imports;

use App\Events\GradeUpdatedEvent;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\CourseClass;
use App\Models\FileBatch;
use App\Models\Rubric;
use App\Models\RubricCriteria;
use App\Models\Student;
use App\Models\StudentRubricScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * PA4 — Nhập điểm THEO TỪNG CÂU HỎI (rubric) cho nhiều lớp trong 1 TRƯỜNG.
 * Cột: Mã lớp · MSSV · Bài kiểm tra · Mã CLO · Điểm đạt · Điểm tối đa.
 *
 * Mỗi dòng = 1 câu hỏi của 1 sinh viên: câu hỏi đó nằm ở bài nào, đo CLO nào, được bao nhiêu
 * trên thang tối đa bao nhiêu. Câu hỏi được lưu thành TIÊU CHÍ RUBRIC của bài đánh giá
 * (`rubric_criteria`), điểm lưu ở `student_rubric_scores` — engine đo CLO từ đây
 * (xem CloCalculationService::cloScoreFromRubric).
 *
 * ⚠️ TÁCH BẠCH với ĐIỂM SỐ: file này KHÔNG ghi `student_grades`. Điểm thành phần (chuyên cần /
 * giữa kỳ / cuối kỳ) dùng để tính điểm tổng kết môn nhập bằng file riêng (PA2 — BulkGradeImport).
 * Đo chuẩn đầu ra và điểm số là hai phép tính độc lập.
 */
class QuestionGradeImport implements ToCollection, WithHeadingRow
{
    use \App\Imports\Concerns\TracksImportProgress;

    /** @var array<string, CourseClass|null> lớp theo mã lớp đã chuẩn hóa */
    private array $classCache = [];

    /** @var array<string, \Illuminate\Support\Collection<int,CourseClass>> lớp theo "mã môn|khóa" */
    private array $classByCourseCache = [];

    /** @var array<int, Collection<int, Assessment>> assessments theo syllabus_id */
    private array $assessmentCache = [];

    /** @var array<string, Clo|null> CLO theo "syllabusId|mã CLO" */
    private array $cloCache = [];

    /** @var array<int, Rubric> rubric theo assessment_id */
    private array $rubricCache = [];

    /** @var array<string, RubricCriteria> tiêu chí theo "rubricId|cloId" */
    private array $criteriaCache = [];

    /** @var array<int, true> cohort đã đánh dấu độ mịn 'question' */
    private array $granularityMarked = [];

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function collection(Collection $rows)
    {
        $rows = $this->realignHeadings($rows);
        $this->batch->update(['total_rows' => $rows->count()]);

        $errors = [];
        /** @var array<string, array{0: Student, 1: CourseClass}> */
        $updatedPairs = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            $classCode = $row['ma_lop'] ?? $row['class_code'] ?? $row['ma_lop_hoc_phan'] ?? null;
            // File gốc của trường chỉ có MÃ HỌC PHẦN, không có mã lớp — suy lớp từ môn + khóa của SV.
            $courseCode = $row['ma_hp'] ?? $row['ma_hoc_phan'] ?? $row['course_code'] ?? null;
            $studentCode = $row['mssv'] ?? $row['mshv'] ?? $row['student_code'] ?? null;
            $assessmentName = $row['bai_kiem_tra'] ?? $row['ten_bai_danh_gia'] ?? $row['bai_danh_gia'] ?? null;
            $cloCode = $row['ma_clo'] ?? $row['clo'] ?? $row['clo_duoc_do'] ?? null;
            $achieved = $row['diem_dat'] ?? $row['diem_dat_duoc'] ?? $row['diem_dat_duoc_cau_hoi'] ?? $row['score'] ?? null;
            $maxScore = $row['diem_toi_da'] ?? $row['diem_toi_da_cau_hoi'] ?? $row['max_score'] ?? null;

            if ((!$classCode && !$courseCode) || !$studentCode || !$assessmentName || !$cloCode || $achieved === null || $maxScore === null) {
                $errors[] = "Dòng {$rowNum}: thiếu dữ liệu (cần Mã lớp hoặc Mã HP, MSSV, Bài kiểm tra, Mã CLO, Điểm đạt, Điểm tối đa).";
                continue;
            }

            if ((float) $maxScore <= 0) {
                $errors[] = "Dòng {$rowNum}: 'Điểm tối đa' phải lớn hơn 0.";
                continue;
            }

            $student = Student::where('school_id', $this->schoolId)
                ->where('student_code', $studentCode)
                ->first();
            if (!$student) {
                $errors[] = "Dòng {$rowNum}: Không tìm thấy sinh viên có mã {$studentCode}.";
                continue;
            }

            if ($classCode) {
                $courseClass = $this->resolveClass((string) $classCode);
                if (!$courseClass) {
                    $errors[] = "Dòng {$rowNum}: Không tìm thấy lớp '{$classCode}' trong trường đã chọn.";
                    continue;
                }
            } else {
                [$courseClass, $err] = $this->resolveClassByCourse((string) $courseCode, $student);
                if (!$courseClass) {
                    $errors[] = "Dòng {$rowNum}: {$err}";
                    continue;
                }
                $classCode = $courseClass->class_code;
            }

            $assessment = $this->resolveAssessment($courseClass, (string) $assessmentName);
            if (!$assessment) {
                $errors[] = "Dòng {$rowNum}: Lớp '{$classCode}' không có bài đánh giá nào tên '{$assessmentName}' — khai báo ở sheet BaiDanhGia của file khung trước.";
                continue;
            }

            $clo = $this->resolveClo((int) $courseClass->syllabus_id, (string) $cloCode);
            if (!$clo) {
                $errors[] = "Dòng {$rowNum}: CLO '{$cloCode}' chưa khai báo cho môn của lớp '{$classCode}'.";
                continue;
            }

            $criteria = $this->resolveCriteria($assessment, $clo, (float) $maxScore);

            StudentRubricScore::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'class_id' => $courseClass->id,
                    'rubric_criteria_id' => $criteria->id,
                ],
                ['score' => (float) $achieved],
            );

            $this->markQuestionGranularity($courseClass);

            $updatedPairs[$student->id . '-' . $courseClass->id] = [$student, $courseClass];
            $this->markRowProcessed();
        }

        $this->flushProgress();

        foreach ($updatedPairs as [$student, $courseClass]) {
            // SV có điểm trong lớp ⇒ ghi danh lớp (nếu chưa), để màn hình lớp/SV không rỗng.
            $courseClass->students()->syncWithoutDetaching([$student->id]);

            // 1 event / (sinh viên, lớp) duy nhất → tính lại CLO/PI/PLO.
            GradeUpdatedEvent::dispatch($student, $courseClass);
        }

        if (!empty($errors)) {
            $this->batch->update([
                'error_log' => array_merge($this->batch->error_log ?? [], $errors),
            ]);
        }
    }

    /**
     * Dòng tên cột thật có thể KHÔNG nằm ở dòng 1: file của trường thường mở đầu bằng một dòng
     * tiêu đề ("QTKD 2023 - Dữ liệu câu hỏi..."). WithHeadingRow khi đó lấy nhầm tiêu đề làm tên
     * cột và MỌI dòng đều báo thiếu dữ liệu. Nếu tên cột thật rơi vào vùng dữ liệu thì đánh khoá lại.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function realignHeadings(Collection $rows): Collection
    {
        $known = ['mssv', 'mshv', 'student_code'];

        // Tên cột đã đúng ⇒ không đụng vào.
        $first = $this->toArray($rows->first());
        if (array_intersect($known, array_keys($first)) !== []) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            $slugs = array_map(
                fn ($v) => Str::slug((string) $v, '_'),
                array_values($this->toArray($row)),
            );
            if (array_intersect($known, $slugs) === []) {
                continue;
            }

            return $rows->slice($i + 1)->map(function ($r) use ($slugs) {
                $values = array_values($this->toArray($r));
                $out = [];
                foreach ($slugs as $pos => $key) {
                    if ($key !== '') {
                        $out[$key] = $values[$pos] ?? null;
                    }
                }

                return $out;
            })->values();
        }

        return $rows;
    }

    /** Dòng do Laravel-Excel trả về có thể là array hoặc Collection — quy về array. */
    private function toArray(mixed $row): array
    {
        return $row instanceof Collection ? $row->all() : (array) $row;
    }

    /**
     * Suy lớp từ MÃ HỌC PHẦN + khóa của sinh viên (khi file không có cột Mã lớp).
     * Nhiều lớp cùng môn trong cùng khóa ⇒ KHÔNG đoán, báo lỗi để người nhập bổ sung Mã lớp.
     *
     * @return array{0: CourseClass|null, 1: string}
     */
    private function resolveClassByCourse(string $courseCode, Student $student): array
    {
        $key = strtolower(trim($courseCode)) . '|' . (int) $student->cohort_id;

        if (! array_key_exists($key, $this->classByCourseCache)) {
            $matches = CourseClass::with('cohort')
                ->where('school_id', $this->schoolId)
                ->when($student->cohort_id, fn ($q) => $q->where('cohort_id', $student->cohort_id))
                ->whereHas('syllabus.course', fn ($q) => $q->whereRaw('LOWER(code) = ?', [strtolower(trim($courseCode))]))
                ->get();

            $this->classByCourseCache[$key] = $matches;
        }

        $matches = $this->classByCourseCache[$key];

        if ($matches->isEmpty()) {
            return [null, "Không tìm thấy lớp nào của học phần '{$courseCode}' cho khóa của sinh viên {$student->student_code}."];
        }
        if ($matches->count() > 1) {
            return [null, "Học phần '{$courseCode}' có {$matches->count()} lớp trong cùng khóa — file cần thêm cột 'Mã lớp'."];
        }

        return [$matches->first(), ''];
    }

    private function resolveClass(string $classCode): ?CourseClass
    {
        $key = strtolower(trim($classCode));
        if (!array_key_exists($key, $this->classCache)) {
            $this->classCache[$key] = CourseClass::with('cohort')
                ->where('school_id', $this->schoolId)
                ->whereRaw('LOWER(class_code) = ?', [$key])
                ->first();
        }

        return $this->classCache[$key];
    }

    /** Khớp CHÍNH XÁC tên bài (không dùng str_contains để "GK1" không nuốt "GK10"). */
    private function resolveAssessment(CourseClass $courseClass, string $name): ?Assessment
    {
        $syllabusId = (int) $courseClass->syllabus_id;

        if (!isset($this->assessmentCache[$syllabusId])) {
            $this->assessmentCache[$syllabusId] = Assessment::where('syllabus_id', $syllabusId)->get();
        }

        $needle = strtolower(trim($name));
        $exact = $this->assessmentCache[$syllabusId]
            ->first(fn (Assessment $a) => strtolower(trim($a->name)) === $needle);

        if ($exact) {
            return $exact;
        }

        // Trường viết tắt bài cuối kỳ là "CK" trong khi khung khai "Cuối kỳ". Chỉ nhận khi môn có
        // ĐÚNG MỘT bài loại 'final' — nhiều bài cuối kỳ thì không đoán, để báo lỗi cho người nhập.
        if (in_array($needle, ['ck', 'cuoi ky', 'cuối kỳ', 'final'], true)) {
            $finals = $this->assessmentCache[$syllabusId]
                ->filter(fn (Assessment $a) => $a->assessment_category === 'final');

            return $finals->count() === 1 ? $finals->first() : null;
        }

        return null;
    }

    private function resolveClo(int $syllabusId, string $code): ?Clo
    {
        $key = $syllabusId . '|' . strtolower(trim($code));
        if (!array_key_exists($key, $this->cloCache)) {
            $this->cloCache[$key] = Clo::where('syllabus_id', $syllabusId)
                ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))])
                ->first();
        }

        return $this->cloCache[$key];
    }

    /**
     * Câu hỏi = 1 tiêu chí rubric (bài đánh giá × CLO). File khách cho điểm tối đa của câu hỏi
     * giống nhau ở mọi sinh viên nên tiêu chí là cấu trúc dùng chung của đề cương.
     */
    private function resolveCriteria(Assessment $assessment, Clo $clo, float $maxScore): RubricCriteria
    {
        $rubric = $this->rubricCache[$assessment->id] ??= Rubric::firstOrCreate(
            ['assessment_id' => $assessment->id],
            ['name' => 'Rubric ' . $assessment->name],
        );

        if (!$assessment->is_rubric_based) {
            $assessment->update(['is_rubric_based' => true]);
        }

        $key = $rubric->id . '|' . $clo->id;

        return $this->criteriaCache[$key] ??= RubricCriteria::updateOrCreate(
            ['rubric_id' => $rubric->id, 'clo_id' => $clo->id],
            ['max_score' => $maxScore, 'description' => "Câu hỏi đo {$clo->code} ({$assessment->name})"],
        );
    }

    /**
     * Đánh dấu khóa "có điểm theo câu hỏi" → CohortMeasurementResolver cho đo lên PI/PLO kể cả
     * khóa ≤2024 (vốn mặc định legacy CLO-only).
     */
    private function markQuestionGranularity(CourseClass $courseClass): void
    {
        $cohort = $courseClass->cohort;
        if (!$cohort || isset($this->granularityMarked[$cohort->id])) {
            return;
        }

        if ($cohort->grade_granularity !== 'question') {
            $cohort->update(['grade_granularity' => 'question']);
        }

        $this->granularityMarked[$cohort->id] = true;
    }
}
