<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cohort;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Services\OBE\ObeRecalculationService;
use Illuminate\Console\Command;

class RecalculateObeCommand extends Command
{
    protected $signature = 'obe:recalculate {--school= : ID trường} {--cohort= : ID khóa (cohort)} {--program= : ID ngành (program)}';

    protected $description = 'Tính lại toàn bộ CLO/PI/PLO cho mọi sinh viên có điểm trong 1 trường và/hoặc 1 khóa/ngành.';

    public function handle(ObeRecalculationService $service): int
    {
        $schoolId = $this->option('school') ? (int) $this->option('school') : null;
        $cohortId = $this->option('cohort') ? (int) $this->option('cohort') : null;
        $programId = $this->option('program') ? (int) $this->option('program') : null;

        if (!$schoolId && !$cohortId && !$programId) {
            $this->error('Cần ít nhất --school, --cohort hoặc --program.');
            return self::FAILURE;
        }

        if ($cohortId && !Cohort::find($cohortId)) {
            $this->error("Không tìm thấy khóa #{$cohortId}.");
            return self::FAILURE;
        }
        if ($programId && !Program::find($programId)) {
            $this->error("Không tìm thấy ngành #{$programId}.");
            return self::FAILURE;
        }

        // Đếm trước để có progress bar.
        $studentIds = Student::query()
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->when($cohortId, fn ($q) => $q->where('cohort_id', $cohortId))
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->pluck('id');

        $total = StudentGrade::whereIn('student_id', $studentIds)
            ->select('student_id', 'class_id')
            ->distinct()
            ->get()
            ->count();

        if ($total === 0) {
            $this->warn('Không có cặp (sinh viên, lớp) nào có điểm trong phạm vi này.');
            return self::SUCCESS;
        }

        $this->info("Bắt đầu tính lại {$total} cặp (sinh viên, lớp)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $done = $service->recalculateScope($schoolId, $programId, $cohortId, fn () => $bar->advance());

        \App\Models\MeasurementRun::create([
            'school_id' => $schoolId,
            'program_id' => $programId,
            'cohort_id' => $cohortId,
            'source' => 'command',
            'pairs_count' => $done,
            'status' => 'completed',
        ]);

        $bar->finish();
        $this->newLine(2);
        $this->info("Hoàn tất: đã tính lại {$done} cặp (sinh viên, lớp).");

        return self::SUCCESS;
    }
}
