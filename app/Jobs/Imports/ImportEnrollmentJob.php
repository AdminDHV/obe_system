<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Imports\EnrollmentImport;
use App\Models\CourseClass;
use App\Models\FileBatch;
use App\Support\AuditActor;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportEnrollmentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 600;

    private FileBatch $batch;
    private CourseClass $courseClass;

    public function __construct(FileBatch $batch, CourseClass $courseClass)
    {
        $this->batch = $batch;
        $this->courseClass = $courseClass;
    }

    public function handle(): void
    {
        $this->batch->update(['status' => 'processing']);

        try {
            $filePath = $this->batch->file_path;

            if (!Storage::exists($filePath)) {
                throw new Exception("Không tìm thấy tệp tin Excel tại đường dẫn: " . $filePath);
            }

            AuditActor::runAs($this->batch->user_id, fn () => Excel::import(
                new EnrollmentImport($this->batch, $this->courseClass),
                $filePath
            ));

            $this->batch->update(['status' => 'completed']);
            Storage::delete($filePath);

        } catch (\Throwable $e) {
            $this->markFailed($e);

            if (isset($filePath) && Storage::exists($filePath)) {
                Storage::delete($filePath);
            }
        }
    }
}
