<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Bản chốt sổ kết quả đo của một khóa/lớp tại một thời điểm. */
class MeasurementSnapshot extends Model
{
    protected $fillable = [
        'uuid', 'school_id', 'program_id', 'cohort_id', 'class_group', 'label',
        'on_track_only', 'pass_threshold', 'target_percent',
        'students_total', 'students_counted', 'taken_by',
    ];

    protected function casts(): array
    {
        return [
            'on_track_only' => 'boolean',
            'pass_threshold' => 'decimal:4',
            'target_percent' => 'decimal:4',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function students(): HasMany
    {
        return $this->hasMany(MeasurementSnapshotStudent::class, 'snapshot_id');
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(MeasurementSnapshotOutcome::class, 'snapshot_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }
}
