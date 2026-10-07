<?php

namespace App\Jobs\Calculations;

use App\Models\Cohort;
use App\Models\Pi;
use App\Models\Plo;
use App\Models\Program;
use App\Models\ReportSnapshot;
use App\Models\Student;
use App\Services\OBE\ObeAttainmentService;
use App\Services\OBE\StudentProgressService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateReportJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900; // 15 minutes for massive reports

    private ReportSnapshot $snapshot;

    /**
     * Create a new job instance.
     */
    public function __construct(ReportSnapshot $snapshot)
    {
        $this->snapshot = $snapshot;
    }

    /**
     * Execute the job.
     */
    public function handle(ObeAttainmentService $attainment, StudentProgressService $progressSvc): void
    {
        $this->snapshot->update(['status' => 'processing']);

        try {
            $filters = $this->snapshot->filters;
            $programId = $filters['program_id'] ?? null;
            $cohortId = $filters['cohort_id'] ?? null;

            if (!$programId || !$cohortId) {
                throw new Exception("Bộ lọc xuất báo cáo không đầy đủ (thiếu program_id hoặc cohort_id).");
            }

            // Ensure the exports directory exists
            if (!Storage::exists('exports')) {
                Storage::makeDirectory('exports');
            }

            $fileName = 'exports/aun_qa_report_' . $this->snapshot->id . '_' . time() . '.csv';
            $filePath = Storage::path($fileName);

            $program = Program::find($programId);
            $cohort = Cohort::find($cohortId);
            $att = $attainment->ploAttainment((int) $programId, (int) $cohortId);

            // WeightedAttainment ra TỈ LỆ 0..1 → hiển thị dạng %; StandardAverage ra điểm thang 10.
            $isRatio = $att['is_ratio'] ?? false;
            $fmt = fn ($v) => $v === null || $v === '' ? '—'
                : ($isRatio ? number_format((float) $v * 100, 1) . '%' : number_format((float) $v, 2));
            $scoreLabel = $isRatio ? 'tỉ lệ đạt %' : 'điểm đạt';

            // Open file for writing
            $file = fopen($filePath, 'w');

            // Add UTF-8 BOM for proper Vietnamese encoding in Excel
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // --- Khối tiêu đề: ngữ cảnh + tham số đo lường ---
            fputcsv($file, ['BÁO CÁO ĐO LƯỜNG CHUẨN ĐẦU RA (AUN-QA)']);
            fputcsv($file, ['Ngành', $program ? $program->code . ' - ' . $program->name : (string) $programId]);
            fputcsv($file, ['Khóa', $cohort?->name ?? (string) $cohortId]);
            fputcsv($file, ['Ngày xuất', now()->format('d/m/Y H:i')]);
            fputcsv($file, ['Ngưỡng đạt PLO', $fmt($att['threshold'])]);
            fputcsv($file, ['Mục tiêu (target) mức đạt', $att['target'] . '%']);
            $hasIndirect = $att['has_indirect'] ?? false;
            if ($hasIndirect) {
                fputcsv($file, ['Trộn trực tiếp/gián tiếp', round(($att['direct_weight'] ?? 0.8) * 100) . '% / ' . round(($att['indirect_weight'] ?? 0.2) * 100) . '%']);
            }

            // Phạm vi gộp thống kê: SV chưa tích lũy đủ tín chỉ được TÁCH RA, vì con số đo trên
            // nửa chương trình không đại diện cho cả khóa (trường phản ánh đúng chỗ này).
            $progress = $progressSvc->forProgram((int) $programId, (int) $cohortId);
            $elig = $att['eligibility'];
            fputcsv($file, ['Số tín chỉ yêu cầu (tốt nghiệp)', $elig['required_credits'] ?? 'Chưa khai']);
            fputcsv($file, ['Ngưỡng tích lũy để gộp thống kê', $elig['required_credits'] === null
                ? 'Không lọc (chưa khai số tín chỉ yêu cầu)'
                : $elig['min_completion_percent'] . '%']);
            fputcsv($file, ['SV gộp vào thống kê / tổng số',
                $elig['students_eligible'] . ' / ' . $elig['students_total']]);
            if ($elig['students_excluded'] > 0) {
                fputcsv($file, ['SV CHƯA đủ tích lũy (xem mục [V])', $elig['students_excluded']]);
            }
            fputcsv($file, []);

            // --- Khối I: Tổng hợp mức đạt chuẩn đầu ra (PLO attainment) ---
            fputcsv($file, ['[I] TỔNG HỢP MỨC ĐẠT CHUẨN ĐẦU RA (PLO ATTAINMENT)']);
            if ($hasIndirect) {
                fputcsv($file, ['Mã PLO', 'Mô tả', 'Số SV đo', 'Số đạt', '% Trực tiếp', '% Gián tiếp', '% Tổng hợp', 'Target %', 'So với mục tiêu (%)', 'Kết quả', 'Mức', $isRatio ? 'TB (tỉ lệ)' : 'Điểm TB']);
            } else {
                fputcsv($file, ['Mã PLO', 'Mô tả', 'Số SV đo', 'Số đạt', '% đạt', 'Target %', 'So với mục tiêu (%)', 'Kết quả', 'Mức', $isRatio ? 'TB (tỉ lệ)' : 'Điểm TB']);
            }
            foreach ($att['plos'] as $r) {
                $hasData = $r['attainment_percent'] !== null;
                $base = [$r['plo_code'], $r['plo_description'], $r['students_measured'], $r['passed']];
                if ($hasIndirect) {
                    $base = array_merge($base, [
                        $r['direct_percent'] ?? '—',
                        $r['indirect_percent'] ?? '—',
                        $hasData ? $r['attainment_percent'] : '—',
                    ]);
                } else {
                    $base[] = $hasData ? $r['attainment_percent'] : '—';
                }
                fputcsv($file, array_merge($base, [
                    $r['target_percent'],
                    $hasData ? \App\Services\OBE\ObeAttainmentService::versusTarget($r['gap']) : '—',
                    !$hasData ? 'Chưa đo' : ($r['met_target'] ? 'Đạt' : 'Chưa đạt'),
                    $hasData ? $r['level'] : '—',
                    $hasData ? $fmt($r['avg_score']) : '—',
                ]));
            }
            fputcsv($file, []);

            // --- Khối II: Ma trận sinh viên × PLO (điểm đạt) ---
            fputcsv($file, ['[II] MA TRẬN SINH VIÊN × PLO (' . $scoreLabel . ')']);
            $plos = Plo::where('program_id', $programId)->orderBy('code')->get();
            $headers = ['MSSV', 'Họ và Tên', 'Đủ tích lũy'];
            foreach ($plos as $plo) {
                $headers[] = $plo->code;
            }
            fputcsv($file, $headers);

            $eligLabel = function (int $studentId) use ($progress): string {
                $row = $progress[$studentId] ?? null;

                return $row === null || $row['completion_percent'] === null
                    ? '—'
                    : ($row['is_eligible'] ? 'Đủ' : 'CHƯA');
            };

            // Fetch students using cursor/lazy loading to avoid memory exhaustion
            Student::where('program_id', $programId)
                ->where('cohort_id', $cohortId)
                ->with(['ploResults'])
                ->lazy()
                ->each(function (Student $student) use ($file, $plos, $fmt, $eligLabel) {
                    $row = [$student->student_code, $student->full_name, $eligLabel($student->id)];

                    foreach ($plos as $plo) {
                        $result = $student->ploResults->firstWhere('plo_id', $plo->id);
                        $row[] = $result ? $fmt($result->achieved_score) : 'N/A';
                    }

                    fputcsv($file, $row);
                });
            fputcsv($file, []);

            // --- Khối III: Tổng hợp mức đạt PI ---
            // CSV trước đây CHỈ có PLO → operator tải về gửi trường là thiếu hẳn PI (thứ hội đồng
            // kiểm định soi). Giữ đồng bộ với bản Excel (AunQaReportExport).
            $piAtt = $attainment->piAttainment((int) $programId, (int) $cohortId);

            fputcsv($file, ['[III] TỔNG HỢP MỨC ĐẠT CHỈ SỐ THỰC HIỆN (PI ATTAINMENT)']);
            fputcsv($file, ['Mã PI', 'Thuộc PLO', 'Mô tả', 'Số SV đo', 'Số đạt', '% đạt', 'Target %', 'Kết quả', 'Mức', $isRatio ? 'TB (tỉ lệ)' : 'Điểm TB']);
            foreach ($piAtt['pis'] as $r) {
                $hasData = $r['attainment_percent'] !== null;
                fputcsv($file, [
                    $r['pi_code'], $r['plo_code'], $r['pi_description'],
                    $r['students_measured'], $r['passed'],
                    $hasData ? $r['attainment_percent'] : '—',
                    $r['target_percent'],
                    !$hasData ? 'Chưa đo' : ($r['met_target'] ? 'Đạt' : 'Chưa đạt'),
                    $hasData ? $r['level'] : '—',
                    $hasData ? $fmt($r['avg_score']) : '—',
                ]);
            }
            fputcsv($file, []);

            // --- Khối IV: Ma trận sinh viên × PI ---
            fputcsv($file, ['[IV] MA TRẬN SINH VIÊN × PI (' . $scoreLabel . ')']);
            $pis = Pi::whereHas('plo', fn ($q) => $q->where('program_id', $programId))
                ->orderBy('code')
                ->get();
            $piHeaders = ['MSSV', 'Họ và Tên', 'Đủ tích lũy'];
            foreach ($pis as $pi) {
                $piHeaders[] = $pi->code;
            }
            fputcsv($file, $piHeaders);

            Student::where('program_id', $programId)
                ->where('cohort_id', $cohortId)
                ->with(['piResults'])
                ->lazy()
                ->each(function (Student $student) use ($file, $pis, $fmt, $eligLabel) {
                    $row = [$student->student_code, $student->full_name, $eligLabel($student->id)];
                    foreach ($pis as $pi) {
                        $result = $student->piResults->firstWhere('pi_id', $pi->id);
                        $row[] = $result ? $fmt($result->achieved_score) : 'N/A';
                    }
                    fputcsv($file, $row);
                });
            fputcsv($file, []);

            // --- Khối V: Tiến độ tích lũy ---
            // Cho hội đồng thấy NGAY mẫu đo gồm những ai: SV học đủ và SV còn đang học dở.
            fputcsv($file, ['[V] TIẾN ĐỘ TÍCH LŨY CỦA SINH VIÊN']);
            fputcsv($file, ['MSSV', 'Họ và Tên', 'Số môn có điểm', 'Số môn đạt', 'TC đã học',
                'TC tích lũy', 'TC yêu cầu', '% tích lũy', 'Đủ điều kiện đo']);
            foreach ($progress as $r) {
                fputcsv($file, [
                    $r['student_code'], $r['full_name'],
                    $r['courses_graded'], $r['courses_passed'],
                    $r['credits_attempted'], $r['credits_earned'],
                    $r['required_credits'] ?? '—',
                    $r['completion_percent'] === null ? '—' : $r['completion_percent'] . '%',
                    $r['completion_percent'] === null ? '—' : ($r['is_eligible'] ? 'Đủ' : 'CHƯA'),
                ]);
            }

            fclose($file);

            // Đảm bảo file đọc được bởi php-fpm (www-data) dù worker chạy root.
            @chmod($filePath, 0644);

            // Complete Snapshot
            $this->snapshot->update([
                'status' => 'generated',
                'file_path' => $fileName,
            ]);

        } catch (\Throwable $e) {
            Log::channel('obe')->error('Tạo báo cáo AUN-QA thất bại', [
                'snapshot_id' => $this->snapshot->id,
                'error' => $e->getMessage(),
            ]);
            $this->snapshot->update([
                'status' => 'failed',
                'file_path' => null,
            ]);

            // Re-throw để queue worker ghi failed_jobs / retry.
            throw $e;
        }
    }

    /** Job chết cứng sau khi hết retry (timeout/OOM) → đảm bảo snapshot không kẹt 'processing'. */
    public function failed(\Throwable $e): void
    {
        $this->snapshot->refresh();
        if (in_array($this->snapshot->status, ['queued', 'processing'], true)) {
            Log::channel('obe')->error('Job báo cáo chết cứng', [
                'snapshot_id' => $this->snapshot->id,
                'error' => $e->getMessage(),
            ]);
            $this->snapshot->update(['status' => 'failed', 'file_path' => null]);
        }
    }
}
