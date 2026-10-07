<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloPiMapping extends Model implements BelongsToSchool
{
    protected $fillable = [
        'clo_id',
        'pi_id',
        'mapping_level',
    ];

    public function clo(): BelongsTo
    {
        return $this->belongsTo(Clo::class);
    }

    public function pi(): BelongsTo
    {
        return $this->belongsTo(Pi::class);
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
        return ($this->clo?->syllabus?->course?->school_id) !== null ? (int) ($this->clo?->syllabus?->course?->school_id) : null;
    }
}
