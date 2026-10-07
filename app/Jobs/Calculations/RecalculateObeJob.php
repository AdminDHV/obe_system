<?php

declare(strict_types=1);

namespace App\Jobs\Calculations;

use App\Models\MeasurementRun;
use App\Services\OBE\ObeRecalculationService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Tính lại toàn bộ CLO/PI/PLO cho 1 khóa và/hoặc 1 ngành (chạy nền).
 * Gọi từ nút "Tính lại toàn khóa" trên trang Báo cáo.
 *
 * Phạm vi nhiều lớp chạy 2 THÌ nối nhau bằng Bus::batch:
 *   thì 1 — mỗi lớp 1 job đo CLO (chạy song song trên nhiều worker-measurements);
 *   thì 2 — sau khi CẢ batch thì 1 xong, gộp PI/PLO một lần cho mỗi sinh viên.
 * Trước đây thì 2 nằm luôn trong job-theo-lớp: vừa tính thừa (SV 16 môn ⇒ 16 lần) vừa để hai
 * worker cùng ghi PI/PLO của một SV, nên bản ghi thắng có thể tính từ trạng thái CLO còn thiếu.
 */
class RecalculateObeJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 1800; // 30 phút cho khóa lớn

    /** Số sinh viên mỗi job gộp PI/PLO. */
    private const AGGREGATE_CHUNK = 100;

    public function __construct(
        private ?int $schoolId,
        private ?int $programId = null,
        private ?int $cohortId = null,
        private ?int $triggeredBy = null,
        private string $source = 'manual',
        private ?int $classId = null,
        private bool $cloOnly = false,
    ) {}

    public function handle(ObeRecalculationService $service): void
    {
        try {
            // Job con của thì 1: đo đúng 1 lớp.
            if ($this->classId !== null) {
                $count = $service->recalculateScope(
                    $this->schoolId, $this->programId, $this->cohortId, null, $this->classId, $this->cloOnly,
                );

                // Chỉ job đơn lẻ mới chốt run; job trong batch để batch chốt sau thì 2.
                if (! $this->cloOnly) {
                    $this->recordRun('completed', $count);
                }

                return;
            }

            $classIds = $service->classIdsInScope($this->schoolId, $this->programId, $this->cohortId);

            // Phạm vi bé: chạy thẳng cả 2 thì, không cần batch.
            if (count($classIds) <= 1) {
                $count = $service->recalculateScope($this->schoolId, $this->programId, $this->cohortId);
                $this->recordRun('completed', $count);

                return;
            }

            $this->dispatchTwoPhaseBatch($service, $classIds);
        } catch (\Throwable $e) {
            // Đo lường lỗi trước đây IM LẶNG (không try/catch → không ghi run nào).
            // Nay log + re-throw; run 'failed' được ghi 1 lần duy nhất ở failed().
            Log::channel('obe')->error('Tính lại CLO/PI/PLO thất bại', [
                'school_id' => $this->schoolId,
                'program_id' => $this->programId,
                'cohort_id' => $this->cohortId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // để queue ghi failed_jobs + gọi failed()
        }
    }

    /**
     * Thì 1 (mỗi lớp 1 job) → khi xong hết thì 2 (mỗi nhóm SV 1 job) → rồi mới chốt run.
     * Run chỉ được ghi 'completed' khi PI/PLO đã gộp xong thật, không phải lúc vừa rải job.
     *
     * @param  array<int,int>  $classIds
     */
    private function dispatchTwoPhaseBatch(ObeRecalculationService $service, array $classIds): void
    {
        $studentIds = $service->studentIdsNeedingAggregation($this->schoolId, $this->programId, $this->cohortId);
        $pairCount = $service->countScopePairs($this->schoolId, $this->programId, $this->cohortId);

        $cloJobs = array_map(
            fn (int $classId) => new self(
                $this->schoolId, $this->programId, $this->cohortId,
                $this->triggeredBy, $this->source, $classId, true,
            ),
            $classIds,
        );

        // Chỉ đóng gói SỐ vào closure (closure của batch bị serialize xuống queue).
        $school = $this->schoolId;
        $program = $this->programId;
        $cohort = $this->cohortId;
        $by = $this->triggeredBy;
        $source = $this->source;
        $chunks = array_chunk($studentIds, self::AGGREGATE_CHUNK);

        Bus::batch($cloJobs)
            ->name("obe:clo school={$school} program={$program} cohort={$cohort}")
            ->onQueue('measurements')
            ->then(function () use ($school, $program, $cohort, $by, $source, $chunks, $pairCount) {
                if ($chunks === []) {
                    self::logRun($school, $program, $cohort, $by, $source, 'completed', $pairCount);

                    return;
                }

                Bus::batch(array_map(fn (array $ids) => new AggregateStudentObeJob($ids), $chunks))
                    ->name("obe:aggregate school={$school} program={$program} cohort={$cohort}")
                    ->onQueue('measurements')
                    ->then(fn () => self::logRun($school, $program, $cohort, $by, $source, 'completed', $pairCount))
                    ->catch(fn () => self::logRun($school, $program, $cohort, $by, $source, 'failed', 0))
                    ->dispatch();
            })
            ->catch(fn () => self::logRun($school, $program, $cohort, $by, $source, 'failed', 0))
            ->dispatch();
    }

    /**
     * Chốt run 'failed' (chỉ 1 lần): fire khi handle() ném ra HOẶC job chết cứng
     * (timeout/OOM) — trước đây đo lường lỗi không để lại dấu vết nào.
     */
    public function failed(\Throwable $e): void
    {
        // Job con trong batch: batch->catch() đã chốt run 'failed' cho cả phạm vi,
        // ghi thêm ở đây sẽ ra nhiều dòng 'failed' cho một lần bấm nút.
        if ($this->cloOnly) {
            return;
        }

        $this->recordRun('failed', 0);
    }

    private function recordRun(string $status, int $count): void
    {
        self::logRun($this->schoolId, $this->programId, $this->cohortId, $this->triggeredBy, $this->source, $status, $count);
    }

    /** Static để gọi được từ closure callback của Bus::batch (không kéo cả job vào closure). */
    public static function logRun(
        ?int $schoolId,
        ?int $programId,
        ?int $cohortId,
        ?int $triggeredBy,
        string $source,
        string $status,
        int $count,
    ): void {
        MeasurementRun::create([
            'school_id' => $schoolId,
            'program_id' => $programId,
            'cohort_id' => $cohortId,
            'triggered_by' => $triggeredBy,
            'source' => $source,
            'pairs_count' => $count,
            'status' => $status,
        ]);
    }
}
