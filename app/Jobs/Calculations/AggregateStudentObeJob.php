<?php

declare(strict_types=1);

namespace App\Jobs\Calculations;

use App\Services\OBE\ObeRecalculationService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * THÌ 2 của phép đo — gộp PI/PLO cho một nhóm sinh viên, sau khi thì 1 (CLO theo lớp) đã xong hết.
 *
 * Tách riêng vì PI/PLO gộp qua MỌI môn của sinh viên: chạy nó trong job-theo-lớp nghĩa là
 * tính lại toàn bộ PI/PLO mỗi khi xong 1 lớp (SV 16 môn ⇒ 16 lần), và hai worker cùng ghi
 * một sinh viên thì bản ghi thắng có thể là bản tính từ trạng thái CLO còn thiếu.
 *
 * @see \App\Jobs\Calculations\RecalculateObeJob điều phối 2 thì bằng Bus::batch
 */
class AggregateStudentObeJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 1800;

    /** @param array<int,int> $studentIds */
    public function __construct(private array $studentIds) {}

    public function handle(ObeRecalculationService $service): void
    {
        try {
            $service->aggregateStudents($this->studentIds);
        } catch (\Throwable $e) {
            Log::channel('obe')->error('Gộp PI/PLO thất bại', [
                'students' => count($this->studentIds),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
