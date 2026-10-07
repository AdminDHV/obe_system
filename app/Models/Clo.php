<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Clo extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'syllabus_id',
        'code',
        'description',
    ];

    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(CourseSyllabus::class, 'syllabus_id');
    }

    public function piMappings(): HasMany
    {
        return $this->hasMany(CloPiMapping::class);
    }

    public function assessmentMappings(): HasMany
    {
        return $this->hasMany(AssessmentCloMapping::class);
    }

    public function rubricCriteria(): HasMany
    {
        return $this->hasMany(RubricCriteria::class);
    }

    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentCloResult::class);
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
        return ($this->syllabus?->course?->school_id) !== null ? (int) ($this->syllabus?->course?->school_id) : null;
    }
}
