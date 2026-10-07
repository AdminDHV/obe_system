<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nhật ký 1 lần ĐO LƯỜNG (tính lại CLO/PI/PLO theo phạm vi) — ai, lúc nào,
 * phạm vi (trường/ngành/khóa), bao nhiêu cặp (sinh viên, lớp) được tính.
 */
class MeasurementRun extends Model
{
    use Prunable;

    protected $fillable = [
        'school_id', 'program_id', 'cohort_id', 'triggered_by', 'source', 'pairs_count', 'status',
    ];

    /** Nhật ký đo lường (tính lại) là dữ liệu vận hành — giữ mặc định 180 ngày. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('obe.measurement_run_keep_days', 180)));
    }

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function program(): BelongsTo { return $this->belongsTo(Program::class); }
    public function cohort(): BelongsTo { return $this->belongsTo(Cohort::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'triggered_by'); }
}
