<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentCloMapping extends Model implements BelongsToSchool
{
    protected $fillable = [
        'assessment_id',
        'clo_id',
        'weight_in_assessment',
    ];

    protected function casts(): array
    {
        return [
            'weight_in_assessment' => 'decimal:2',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function clo(): BelongsTo
    {
        return $this->belongsTo(Clo::class);
    }

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
        return ($this->assessment?->syllabus?->course?->school_id) !== null ? (int) ($this->assessment?->syllabus?->course?->school_id) : null;
    }
}
