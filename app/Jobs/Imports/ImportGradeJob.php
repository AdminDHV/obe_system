<?php

namespace App\Jobs\Imports;

use App\Imports\GradeImport;
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

class ImportGradeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 600; // 10 minutes for large files

    private FileBatch $batch;
    private CourseClass $courseClass;

    /**
     * Create a new job instance.
     */
    public function __construct(FileBatch $batch, CourseClass $courseClass)
    {
        $this->batch = $batch;
        $this->courseClass = $courseClass;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->batch->update(['status' => 'processing']);

        try {
            $filePath = $this->batch->file_path;

            if (!Storage::exists($filePath)) {
                throw new Exception("Không tìm thấy tệp tin Excel tại đường dẫn: " . $filePath);
            }

            // Perform import using GradeImport class (quy thay đổi điểm cho người upload).
            AuditActor::runAs($this->batch->user_id, fn () => Excel::import(
                new GradeImport($this->batch, $this->courseClass),
                $filePath
            ));

            // Cleanup & Update Batch status
            $this->batch->update(['status' => 'completed']);
            Storage::delete($filePath);

        } catch (\Throwable $e) {
            $this->markFailed($e);

            // Delete file even if it failed to clean up storage
            if (isset($filePath) && Storage::exists($filePath)) {
                Storage::delete($filePath);
            }
        }
    }
}
