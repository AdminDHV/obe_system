<?php

namespace App\Imports;

use App\Events\GradeUpdatedEvent;
use App\Models\Assessment;
use App\Models\Clo;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseSyllabus;
use App\Models\FileBatch;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Nhập điểm KHÓA CŨ (≤2024) TỰ CHỨA: chỉ cần file điểm, hệ thống tự xác lập
 * khóa / lớp / sinh viên / bài "Điểm tổng kết" từ chính file — operator KHÔNG
 * phải dựng khóa/lớp tay.
 *
 * Cột: Mã ngành · Năm khóa · Mã môn · MSSV · Điểm (+ Tên SV tùy chọn).
 * Điều kiện DUY NHẤT phải có sẵn: Ngành + Môn + CLO (vì đó là thứ để đo).
 * Điểm tổng kết được gán cho TẤT CẢ CLO của môn (đúng luật phương án 1).
 */
class LegacyGradeImport implements ToCollection, WithHeadingRow
{
    use \App\Imports\Concerns\TracksImportProgress;

    private array $cohortCache = [];
    private array $classCache = [];
    private ?User $legacyLecturer = null;

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function collection(Collection $rows)
    {
        $this->batch->update(['total_rows' => $rows->count()]);
        $errors = [];
        $updatedPairs = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            $progCode = $this->cell($row, 'ma_nganh', 'program_code');
            $year = $this->cell($row, 'nam_khoa', 'nam', 'start_year');
            $courseCode = $this->cell($row, 'ma_mon', 'course_code');
            $mssv = $this->cell($row, 'mssv', 'student_code', 'ma_sinh_vien');
            $score = $row['diem'] ?? $row['score'] ?? $row['diem_so'] ?? null;

            if (!$progCode || !$year || !$courseCode || !$mssv || $score === null) {
                $errors[] = "Dòng {$rowNum}: thiếu dữ liệu (Mã ngành, Năm khóa, Mã môn, MSSV hoặc Điểm).";
                continue;
            }

            $program = Program::where('school_id', $this->schoolId)->where('code', $progCode)->first();
            if (!$program) {
                $errors[] = "Dòng {$rowNum}: chưa có ngành '{$progCode}' — khai báo ngành (file Khung) trước.";
                continue;
            }
            $course = Course::where('school_id', $this->schoolId)->where('code', $courseCode)->first();
            if (!$course) {
                $errors[] = "Dòng {$rowNum}: chưa có môn '{$courseCode}' — khai báo môn + CLO (file Khung) trước.";
                continue;
            }
            $syllabus = $this->activeSyllabus($course);
            if (Clo::where('syllabus_id', $syllabus->id)->doesntExist()) {
                $errors[] = "Dòng {$rowNum}: môn '{$courseCode}' chưa có CLO — không có gì để đo. Khai báo CLO (file Khung) trước.";
                continue;
            }

            $cohort = $this->resolveCohort($program, (int) $year);
            $courseClass = $this->resolveClass($course, $syllabus, $cohort);

            $student = Student::firstOrCreate(
                ['school_id' => $this->schoolId, 'student_code' => (string) $mssv],
                [
                    'full_name' => $this->cell($row, 'ten_sv', 'ho_ten', 'full_name') ?? (string) $mssv,
                    'cohort_id' => $cohort->id,
                    'program_id' => $program->id,
                    'status' => 'studying',
                ],
            );
            $courseClass->students()->syncWithoutDetaching([$student->id]);

            $carrier = Assessment::firstOrCreate(
                ['syllabus_id' => $syllabus->id, 'name' => 'Điểm tổng kết'],
                ['assessment_category' => 'final', 'weight_percent' => 100, 'is_rubric_based' => false],
            );

            StudentGrade::updateOrCreate(
                ['student_id' => $student->id, 'class_id' => $courseClass->id, 'assessment_id' => $carrier->id],
                ['score' => (float) $score, 'is_legacy_data' => true],
            );

            $updatedPairs[$student->id . '-' . $courseClass->id] = [$student, $courseClass];
            $this->markRowProcessed();
        }

        $this->flushProgress();

        foreach ($updatedPairs as [$student, $courseClass]) {
            GradeUpdatedEvent::dispatch($student, $courseClass);
        }

        if (!empty($errors)) {
            $this->batch->update(['error_log' => array_merge($this->batch->error_log ?? [], $errors)]);
        }
    }

    private function cell($row, string ...$keys): ?string
    {
        foreach ($keys as $k) {
            $v = $row[$k] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }
        return null;
    }

    private function activeSyllabus(Course $course): CourseSyllabus
    {
        return CourseSyllabus::firstOrCreate(
            ['course_id' => $course->id, 'version' => '1.0'],
            ['is_active' => true],
        );
    }

    private function resolveCohort(Program $program, int $year): Cohort
    {
        $key = $program->id . '-' . $year;
        return $this->cohortCache[$key] ??= Cohort::firstOrCreate(
            ['program_id' => $program->id, 'name' => 'Khóa ' . $year],
            ['start_year' => $year, 'school_id' => $this->schoolId],
        );
    }

    private function legacyLecturer(): User
    {
        return $this->legacyLecturer ??= User::firstOrCreate(
            ['staff_code' => 'LEGACY-' . $this->schoolId],
            ['full_name' => 'Giảng viên (dữ liệu cũ)', 'email' => 'legacy-' . $this->schoolId . '@system.local', 'password' => bcrypt(Str::random(32))],
        );
    }

    private function resolveClass(Course $course, CourseSyllabus $syllabus, Cohort $cohort): CourseClass
    {
        $code = $course->code . '-' . $cohort->start_year . '-LEGACY';
        return $this->classCache[$code] ??= CourseClass::firstOrCreate(
            ['school_id' => $this->schoolId, 'class_code' => $code],
            [
                'syllabus_id' => $syllabus->id,
                'cohort_id' => $cohort->id,
                'lecturer_id' => $this->legacyLecturer()->id,
                'academic_year' => $cohort->start_year . '-' . ($cohort->start_year + 1),
                'semester' => 1,
            ],
        );
    }
}
