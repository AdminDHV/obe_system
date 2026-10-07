<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Danh sách MÃ TRA CỨU chứa bí mật của từng học viên — dọn sớm hơn export thường (7 ngày),
// đủ để khoa tải về và phát, không để nằm lại trên đĩa vô thời hạn.
Schedule::call(function () {
    foreach (Storage::files('lookup-codes') as $file) {
        if (time() - Storage::lastModified($file) > 7 * 24 * 60 * 60) {
            Storage::delete($file);
        }
    }
})->daily();

Schedule::call(function () {
    if (Storage::exists('exports')) {
        $files = Storage::files('exports');
        foreach ($files as $file) {
            $lastModified = Storage::lastModified($file);
            if (time() - $lastModified > 30 * 24 * 60 * 60) { // 30 days
                Storage::delete($file);
            }
        }
    }
})->daily();

// Dọn nhật ký quá hạn (Prunable): audit_logs ~2 năm, measurement_runs ~180 ngày.
// KHÔNG prune file_batches (giữ làm lịch sử import + tránh mồ côi file gốc).
Schedule::command('model:prune', [
    '--model' => [\App\Models\AuditLog::class, \App\Models\MeasurementRun::class],
])->daily();
