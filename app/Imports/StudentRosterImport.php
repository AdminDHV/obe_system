<?php

namespace App\Imports;

use App\Models\Cohort;
use App\Models\FileBatch;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Nhập DANH SÁCH SINH VIÊN hàng loạt cho 1 trường — thay cho việc thêm tay từng SV.
 * Chỉ ghi bảng students (KHÔNG đụng điểm/lớp). MSSV trùng → cập nhật, không nhân đôi.
 *
 * Cột: MSSV · Họ tên · Mã ngành · Năm khóa (hoặc Mã khóa/Tên khóa) (+ Tên SV alias).
 * Cột TUỲ CHỌN 'Lớp' = lớp sinh hoạt (đơn vị CVHT theo dõi); bỏ trống thì báo cáo gộp theo khóa.
 * Điều kiện phải có sẵn: Ngành (khai ở file Khung / Bước 1).
 */
class StudentRosterImport implements ToCollection, WithHeadingRow
{
    use \App\Imports\Concerns\TracksImportProgress;

    private array $cohortCache = [];

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function collection(Collection $rows): void
    {
        $this->batch->update(['total_rows' => $rows->count()]);
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            $mssv = $this->cell($row, 'mssv', 'student_code', 'ma_sinh_vien');
            $fullName = $this->cell($row, 'ho_ten', 'ten_sv', 'full_name', 'ten');
            $progCode = $this->cell($row, 'ma_nganh', 'program_code');

            if (!$mssv || !$fullName || !$progCode) {
                $errors[] = "Dòng {$rowNum}: thiếu MSSV / Họ tên / Mã ngành.";
                continue;
            }

            $program = Program::where('school_id', $this->schoolId)->where('code', $progCode)->first();
            if (!$program) {
                $errors[] = "Dòng {$rowNum}: chưa có ngành '{$progCode}' — khai báo ngành (file Khung) trước.";
                continue;
            }

            $cohort = $this->resolveCohort($program, $row);
            if (!$cohort) {
                $errors[] = "Dòng {$rowNum}: thiếu Năm khóa hoặc Mã khóa (để xếp SV vào khóa).";
                continue;
            }

            $attrs = [
                'full_name' => $fullName,
                'cohort_id' => $cohort->id,
                'program_id' => $program->id,
                'status' => 'studying',
            ];

            // Chỉ ghi đè khi file CÓ khai lớp — tránh import lần sau xoá mất lớp đã gán tay.
            $classGroup = $this->cell($row, 'lop', 'lop_sinh_hoat', 'class_group', 'class');
            if ($classGroup !== null) {
                $attrs['class_group'] = $classGroup;
            }

            Student::updateOrCreate(
                ['school_id' => $this->schoolId, 'student_code' => (string) $mssv],
                $attrs,
            );

            $this->markRowProcessed();
        }

        $this->flushProgress();

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

    /** Xếp SV vào khóa: ưu tiên Tên/Mã khóa nếu có, nếu không thì theo Năm khóa. */
    private function resolveCohort(Program $program, $row): ?Cohort
    {
        $name = $this->cell($row, 'ten_khoa', 'ma_khoa', 'cohort');
        $year = $this->cell($row, 'nam_khoa', 'nam', 'start_year');

        if (!$name && !$year) {
            return null;
        }

        $name ??= 'Khóa ' . $year;
        $key = $program->id . '-' . $name;

        return $this->cohortCache[$key] ??= Cohort::firstOrCreate(
            ['program_id' => $program->id, 'name' => $name],
            ['start_year' => (int) ($year ?: 0), 'school_id' => $this->schoolId],
        );
    }
}
