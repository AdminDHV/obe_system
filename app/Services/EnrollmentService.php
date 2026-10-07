<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\FileBatch;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EnrollmentService
{
    public function importFromExcel(Collection $rows, CourseClass $class, FileBatch $batch): array
    {
        $errors = [];
        $succeeded = 0;
        $skipped = 0;
        $currentCount = Enrollment::where('class_id', $class->id)
            ->where('status', 'active')
            ->count();

        $existingEnrollments = Enrollment::where('class_id', $class->id)
            ->get()
            ->keyBy('student_id');

        foreach ($rows as $index => $row) {
            $studentCode = $row['mssv'] ?? $row['ma_sinh_vien'] ?? $row['student_code'] ?? null;
            $rowNum = $index + 2;

            if (!$studentCode) {
                $errors[] = "Dòng {$rowNum}: MSSV trống.";
                continue;
            }

            $student = Student::where('student_code', $studentCode)->first();

            if (!$student) {
                $errors[] = "Dòng {$rowNum}: MSSV '{$studentCode}' không tồn tại trong hệ thống.";
                continue;
            }

            // Cohort alignment enforcement
            if ($student->cohort_id !== $class->cohort_id) {
                $errors[] = "Dòng {$rowNum}: SV '{$studentCode}' ({$student->full_name}) thuộc khóa khác với lớp (SV: " . ($student->cohort?->name ?? 'N/A') . ", Lớp: " . ($class->cohort?->name ?? 'N/A') . "). Đã bỏ qua.";
                continue;
            }

            if ($existingEnrollments->has($student->id)) {
                $enrollment = $existingEnrollments->get($student->id);
                // Re-enroll if completed or withdrawn
                if (in_array($enrollment->status, ['completed', 'withdrawn'], true)) {
                    $enrollment->update([
                        'status' => 'active',
                        'enrolled_at' => Carbon::now(),
                    ]);
                    $succeeded++;
                } else {
                    $skipped++;
                }
                continue;
            }

            // Capacity check
            if ($currentCount + ($succeeded - $skipped) >= $class->max_capacity) {
                $errors[] = "Dòng {$rowNum}: Lớp đã đạt sức chứa tối đa ({$class->max_capacity} SV). Không thể thêm SV '{$studentCode}'.";
                continue;
            }

            Enrollment::create([
                'class_id' => $class->id,
                'student_id' => $student->id,
                'enrolled_at' => Carbon::now(),
                'status' => 'active',
            ]);

            $succeeded++;
        }

        $batch->increment('processed_rows', $succeeded + $skipped);

        if (!empty($errors)) {
            $existingErrors = $batch->error_log ?? [];
            $batch->update([
                'error_log' => array_merge($existingErrors, $errors),
            ]);
        }

        return [
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function updateStatus(Enrollment $enrollment, string $newStatus): void
    {
        $allowed = [
            'active' => ['locked', 'suspended', 'completed', 'withdrawn'],
            'locked' => ['active', 'suspended', 'withdrawn'],
            'suspended' => ['active', 'withdrawn'],
            'completed' => [],
            'withdrawn' => [],
        ];

        $current = $enrollment->status;

        if (!isset($allowed[$current]) || !in_array($newStatus, $allowed[$current], true)) {
            throw new \InvalidArgumentException(
                "Không thể chuyển trạng thái từ '{$current}' sang '{$newStatus}'."
            );
        }

        $enrollment->update(['status' => $newStatus]);
    }

    public function getClassEnrollments(CourseClass $class): Collection
    {
        return Enrollment::where('class_id', $class->id)
            ->with('student')
            ->get()
            ->map(fn(Enrollment $e) => [
                'id' => $e->id,
                'student_code' => $e->student->student_code,
                'full_name' => $e->student->full_name,
                'status' => $e->status,
                'enrolled_at' => $e->enrolled_at?->format('d/m/Y H:i'),
                'uuid' => $e->student->uuid,
            ]);
    }
}
