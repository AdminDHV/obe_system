<?php

namespace App\Listeners;

use App\Events\GradeUpdatedEvent;
use App\Services\OBE\ObeRecalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecalculateObeScoresListener implements ShouldQueue
{
    // Chạy trên queue 'measurements' riêng (worker riêng) để không block các
    // import/export người dùng trên queue 'default'.
    public $queue = 'measurements';

    public function __construct(
        private ObeRecalculationService $recalcService,
    ) {}

    public function handle(GradeUpdatedEvent $event): void
    {
        $this->recalcService->recalculateStudentInClass($event->student, $event->courseClass);
    }
}
