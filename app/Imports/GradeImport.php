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

class GradeImport implements ToCollection, WithHeadingRow
{
    use \App\Imports\Concerns\TracksImportProgress;

    private FileBatch $batch;
    private CourseClass $courseClass;

    public function __construct(FileBatch $batch, CourseClass $courseClass)
    {
        $this->batch = $batch;
        $this->courseClass = $courseClass;
    }

    public function collection(Collection $rows)
    {
        $this->courseClass->load('cohort');
        $syllabus = $this->courseClass->syllabus;
        $assessments = Assessment::where('syllabus_id', $syllabus->id)->get();
        $isLegacy = $this->courseClass->cohort
            && CohortStrategy::fromStartYear((int) $this->courseClass->cohort->start_year)->isLegacy();
        $errors = [];
        $updatedStudentIds = [];

        foreach ($rows as $index => $row) {
            $studentCode = $row['mssv'] ?? $row['student_code'] ?? $row['ma_sinh_vien'] ?? null;
            $assessmentName = $row['ten_bai_danh_gia'] ?? $row['assessment_name'] ?? $row['bai_danh_gia'] ?? null;
            $score = $row['diem'] ?? $row['score'] ?? $row['diem_so'] ?? null;

            $rowNum = $index + 2;

            if (!$studentCode || !$assessmentName || $score === null) {
                $errors[] = "Dòng {$rowNum}: Dữ liệu không đầy đủ (MSSV, Tên bài đánh giá hoặc Điểm bị trống).";
                continue;
            }

            $student = Student::where('student_code', $studentCode)->first();
            if (!$student) {
                $errors[] = "Dòng {$rowNum}: Không tìm thấy sinh viên có mã {$studentCode} trong hệ thống.";
                continue;
            }

            $assessment = $assessments->first(function ($value) use ($assessmentName) {
                return str_contains(strtolower($value->name), strtolower($assessmentName));
            });

            if (!$assessment) {
                $errors[] = "Dòng {$rowNum}: Không tìm thấy bài đánh giá nào có tên khớp với '{$assessmentName}'.";
                continue;
            }

            StudentGrade::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'class_id' => $this->courseClass->id,
                    'assessment_id' => $assessment->id,
                ],
                [
                    'score' => (float) $score,
                    'is_legacy_data' => $isLegacy,
                ]
            );

            $updatedStudentIds[] = $student->id;
            $this->markRowProcessed();
        }

        $this->flushProgress();

        // Dispatch ONE event per unique student after loop
        foreach (array_unique($updatedStudentIds) as $studentId) {
            $student = Student::find($studentId);
            if ($student) {
                // Ghi danh SV vào lớp — màn hình Lớp đọc danh sách qua bảng ghi danh.
                $this->courseClass->students()->syncWithoutDetaching([$student->id]);

                GradeUpdatedEvent::dispatch($student, $this->courseClass);
            }
        }

        if (!empty($errors)) {
            $existingErrors = $this->batch->error_log ?? [];
            $this->batch->update([
                'error_log' => array_merge($existingErrors, $errors)
            ]);
        }
    }
}
