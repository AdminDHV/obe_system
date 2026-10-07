<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Việc CẢI TIẾN (CQI/PDCA) gắn với 1 PLO chưa đạt mục tiêu của 1 ngành (+khóa).
 */
class CqiAction extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'school_id', 'program_id', 'cohort_id', 'plo_id',
        'measurement_run_id', 'report_snapshot_id',
        'attainment_percent', 'target_percent',
        'finding', 'root_cause', 'action_plan', 'owner', 'status',
    ];

    protected function casts(): array
    {
        return [
            'attainment_percent' => 'decimal:2',
            'target_percent' => 'decimal:2',
        ];
    }

    public function program(): BelongsTo { return $this->belongsTo(Program::class); }
    public function cohort(): BelongsTo { return $this->belongsTo(Cohort::class); }
    public function plo(): BelongsTo { return $this->belongsTo(Plo::class); }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
        static::saving(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /** Truy ngược quan hệ vì bảng này không mang school_id. */
    public function schoolId(): ?int
    {
        return ($this->program?->school_id) !== null ? (int) ($this->program?->school_id) : null;
    }
}
