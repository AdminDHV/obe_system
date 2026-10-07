<?php

namespace App\Imports;

use App\Enums\CohortStrategy;
use App\Events\GradeUpdatedEvent;
use App\Models\Assessment;
use App\Models\CourseClass;
use App\Models\FileBatch;
use App\Models\Student;
use App\Models\StudentGrade;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Nhập điểm HÀNG LOẠT cho nhiều lớp trong 1 TRƯỜNG qua 1 file duy nhất.
 * Cột: Mã lớp · MSSV · Tên bài đánh giá · Điểm.
 *
 * Mỗi dòng TỰ ĐỊNH TUYẾN về đúng lớp theo Mã lớp (unique trong trường) — KHÔNG cần chọn khóa.
 * Cohort + phương án (is_legacy_data) suy từ lớp/cohort.start_year.
 */
class BulkGradeImport implements ToCollection, WithHeadingRow
{
    use \App\Imports\Concerns\TracksImportProgress;

    /** @var array<string, CourseClass|null> cache lớp theo mã lớp (đã chuẩn hóa) */
    private array $classCache = [];

    /** @var array<int, \Illuminate\Support\Collection> cache assessments theo class_id */
    private array $assessmentCache = [];

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function collection(Collection $rows)
    {
        $this->batch->update(['total_rows' => $rows->count()]);

        $errors = [];
        // cặp (studentId => courseClass) đã cập nhật, để bắn 1 event/cặp.
        $updatedPairs = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            $classCode = $row['ma_lop'] ?? $row['class_code'] ?? $row['ma_lop_hoc_phan'] ?? null;
            $studentCode = $row['mssv'] ?? $row['student_code'] ?? $row['ma_sinh_vien'] ?? null;
            $assessmentName = $row['ten_bai_danh_gia'] ?? $row['assessment_name'] ?? $row['bai_danh_gia'] ?? null;
            $score = $row['diem'] ?? $row['score'] ?? $row['diem_so'] ?? null;

            // Bắt buộc: Mã lớp · MSSV · Điểm. 'Tên bài đánh giá' chỉ bắt buộc cho khóa
            // KHÔNG legacy (khóa cũ dùng file 3 cột: Mã lớp · MSSV · Điểm).
            if (!$classCode || !$studentCode || $score === null) {
                $errors[] = "Dòng {$rowNum}: Dữ liệu không đầy đủ (Mã lớp, MSSV hoặc Điểm bị trống).";
                continue;
            }

            $courseClass = $this->resolveClass((string) $classCode);
            if (!$courseClass) {
                $errors[] = "Dòng {$rowNum}: Không tìm thấy lớp '{$classCode}' trong trường đã chọn.";
                continue;
            }

            $student = Student::where('school_id', $this->schoolId)
                ->where('student_code', $studentCode)
                ->first();
            if (!$student) {
                $errors[] = "Dòng {$rowNum}: Không tìm thấy sinh viên có mã {$studentCode}.";
                continue;
            }

            // Khóa khai báo `grade_granularity='question'` có điểm THÀNH PHẦN thật (chuyên cần/giữa kỳ/
            // cuối kỳ) — dù năm ≤2024 vẫn KHÔNG phải legacy: mỗi dòng phải khớp đúng tên bài đánh giá,
            // không được rơi vào nhánh "carrier" gán điểm tổng cho mọi CLO.
            $isLegacy = $courseClass->cohort
                && CohortStrategy::fromStartYear((int) $courseClass->cohort->start_year)->isLegacy()
                && ($courseClass->cohort->grade_granularity ?? null) !== 'question';

            if (!$isLegacy && !$assessmentName) {
                $errors[] = "Dòng {$rowNum}: Khóa của lớp '{$classCode}' cần cột 'Tên bài đánh giá' cho mỗi dòng điểm.";
                continue;
            }

            $assessments = $this->assessmentsFor($courseClass);
            $assessment = $assessmentName
                ? $assessments->first(fn ($a) => str_contains(strtolower($a->name), strtolower((string) $assessmentName)))
                : null;

            // Khóa cũ (≤2024): điểm tổng kết được gán cho MỌI CLO nên bài đánh giá
            // chỉ là "carrier". Thiếu hoặc không khớp tên bài → dùng bài đánh giá
            // đầu tiên của syllabus thay vì báo lỗi.
            if (!$assessment && $isLegacy) {
                $assessment = $assessments->first();
            }

            if (!$assessment) {
                $errors[] = $isLegacy
                    ? "Dòng {$rowNum}: Lớp '{$classCode}' là khóa cũ nhưng môn chưa có bài đánh giá nào để chứa điểm tổng kết — tạo 1 bài đánh giá (vd 'Điểm tổng kết') cho syllabus rồi import lại."
                    : "Dòng {$rowNum}: Lớp '{$classCode}' không có bài đánh giá nào khớp '{$assessmentName}'.";
                continue;
            }

            StudentGrade::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'class_id' => $courseClass->id,
                    'assessment_id' => $assessment->id,
                ],
                [
                    'score' => (float) $score,
                    'is_legacy_data' => $isLegacy,
                ]
            );

            $updatedPairs[$student->id . '-' . $courseClass->id] = [$student, $courseClass];
            $this->markRowProcessed();
        }

        $this->flushProgress();

        // Bắn 1 event / (sinh viên, lớp) duy nhất.
        foreach ($updatedPairs as [$student, $courseClass]) {
            // SV có điểm trong lớp ⇒ ghi danh (nếu chưa). Thiếu bước này thì màn hình Lớp liệt kê
            // sinh viên QUA BẢNG GHI DANH nên hiện rỗng dù đã có điểm.
            $courseClass->students()->syncWithoutDetaching([$student->id]);

            GradeUpdatedEvent::dispatch($student, $courseClass);
        }

        if (!empty($errors)) {
            $this->batch->update([
                'error_log' => array_merge($this->batch->error_log ?? [], $errors),
            ]);
        }
    }

    private function resolveClass(string $classCode): ?CourseClass
    {
        $key = strtolower(trim($classCode));
        if (!array_key_exists($key, $this->classCache)) {
            $this->classCache[$key] = CourseClass::with('cohort', 'syllabus')
                ->where('school_id', $this->schoolId)
                ->whereRaw('LOWER(class_code) = ?', [$key])
                ->first();
        }

        return $this->classCache[$key];
    }

    private function assessmentsFor(CourseClass $courseClass): Collection
    {
        if (!isset($this->assessmentCache[$courseClass->id])) {
            $this->assessmentCache[$courseClass->id] = Assessment::where('syllabus_id', $courseClass->syllabus_id)->get();
        }

        return $this->assessmentCache[$courseClass->id];
    }
}
