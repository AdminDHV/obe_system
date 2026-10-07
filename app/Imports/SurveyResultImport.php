<?php

namespace App\Imports;

use App\Models\Cohort;
use App\Models\FileBatch;
use App\Models\Plo;
use App\Models\Program;
use App\Models\SurveyInstrument;
use App\Models\SurveyPloResult;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Nhập kết quả khảo sát (đo GIÁN TIẾP) theo PLO cho 1 TRƯỜNG.
 * Cột: Mã ngành · Mã khóa (tùy chọn) · Đối tượng · Mã PLO · Số phản hồi · Điểm TB Likert · Thang.
 *
 * Mỗi dòng = mức đạt 1 PLO theo 1 nhóm bên liên quan. Tổng hợp (KHÔNG per-respondent).
 */
class SurveyResultImport implements ToCollection, WithHeadingRow
{
    use \App\Imports\Concerns\TracksImportProgress;

    /** @var array<string, Program|null> */
    private array $programCache = [];

    public function __construct(
        private FileBatch $batch,
        private int $schoolId,
    ) {}

    public function collection(Collection $rows)
    {
        $this->batch->update(['total_rows' => $rows->count()]);
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            $programCode = $row['ma_nganh'] ?? $row['program_code'] ?? null;
            $cohortCode = $row['ma_khoa'] ?? $row['cohort'] ?? null;
            $stakeholderRaw = $row['doi_tuong'] ?? $row['stakeholder'] ?? null;
            $ploCode = $row['ma_plo'] ?? $row['plo'] ?? null;
            $respondents = $row['so_phan_hoi'] ?? $row['respondents'] ?? 0;
            $mean = $row['diem_tb_likert'] ?? $row['mean'] ?? null;
            $likertMax = (int) ($row['thang'] ?? $row['likert_max'] ?? 5);

            if (!$programCode || !$stakeholderRaw || !$ploCode || $mean === null || $mean === '') {
                $errors[] = "Dòng {$rowNum}: Thiếu dữ liệu (Mã ngành, Đối tượng, Mã PLO hoặc Điểm TB Likert).";
                continue;
            }

            $stakeholder = $this->normalizeStakeholder((string) $stakeholderRaw);
            if (!$stakeholder) {
                $errors[] = "Dòng {$rowNum}: Đối tượng '{$stakeholderRaw}' không hợp lệ (cuối môn/khóa học, tốt nghiệp, cựu SV, nhà tuyển dụng).";
                continue;
            }

            $program = $this->resolveProgram((string) $programCode);
            if (!$program) {
                $errors[] = "Dòng {$rowNum}: Không tìm thấy ngành '{$programCode}' trong trường đã chọn.";
                continue;
            }

            $plo = Plo::where('program_id', $program->id)
                ->whereRaw('LOWER(code) = ?', [strtolower(trim((string) $ploCode))])
                ->first();
            if (!$plo) {
                $errors[] = "Dòng {$rowNum}: Không tìm thấy PLO '{$ploCode}' trong ngành '{$programCode}'.";
                continue;
            }

            $cohortId = null;
            if ($cohortCode) {
                $cohortId = Cohort::where('program_id', $program->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower(trim((string) $cohortCode))])
                    ->value('id');
            }

            if ($likertMax < 2) {
                $likertMax = 5;
            }
            $ratio = max(0.0, min(1.0, ((float) $mean - 1) / ($likertMax - 1)));

            $instrument = SurveyInstrument::updateOrCreate(
                [
                    'school_id' => $this->schoolId,
                    'program_id' => $program->id,
                    'cohort_id' => $cohortId,
                    'stakeholder' => $stakeholder,
                ],
                ['likert_max' => $likertMax],
            );

            SurveyPloResult::updateOrCreate(
                ['survey_instrument_id' => $instrument->id, 'plo_id' => $plo->id],
                [
                    'respondents' => (int) $respondents,
                    'mean_likert' => (float) $mean,
                    'attainment_ratio' => $ratio,
                ],
            );

            $this->markRowProcessed();
        }

        $this->flushProgress();

        if (!empty($errors)) {
            $this->batch->update([
                'error_log' => array_merge($this->batch->error_log ?? [], $errors),
            ]);
        }
    }

    private function resolveProgram(string $code): ?Program
    {
        $key = strtolower(trim($code));
        if (!array_key_exists($key, $this->programCache)) {
            $this->programCache[$key] = Program::where('school_id', $this->schoolId)
                ->whereRaw('LOWER(code) = ?', [$key])
                ->first();
        }

        return $this->programCache[$key];
    }

    /** Map nhãn tiếng Việt / mã → enum stakeholder. */
    private function normalizeStakeholder(string $raw): ?string
    {
        $s = strtolower(trim($raw));
        $map = [
            'course_end' => 'course_end', 'graduating' => 'graduating', 'alumni' => 'alumni', 'employer' => 'employer',
        ];
        if (isset($map[$s])) {
            return $map[$s];
        }
        if (str_contains($s, 'tuyển') || str_contains($s, 'tuyen') || str_contains($s, 'doanh nghiệp') || str_contains($s, 'doanh nghiep') || str_contains($s, 'employer')) {
            return 'employer';
        }
        if (str_contains($s, 'cựu') || str_contains($s, 'cuu') || str_contains($s, 'alumni')) {
            return 'alumni';
        }
        if (str_contains($s, 'tốt nghiệp') || str_contains($s, 'tot nghiep') || str_contains($s, 'graduat')) {
            return 'graduating';
        }
        if (str_contains($s, 'cuối') || str_contains($s, 'cuoi') || str_contains($s, 'môn') || str_contains($s, 'mon') || str_contains($s, 'khóa học') || str_contains($s, 'khoa hoc') || str_contains($s, 'course')) {
            return 'course_end';
        }

        return null;
    }
}
