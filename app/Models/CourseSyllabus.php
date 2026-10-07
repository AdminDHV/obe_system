<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseSyllabus extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $table = 'course_syllabus';

    protected $fillable = [
        'course_id',
        'version',
        'is_active',
        'frame_locked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'frame_locked_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function clos(): HasMany
    {
        return $this->hasMany(Clo::class, 'syllabus_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'syllabus_id');
    }

    public function courseClasses(): HasMany
    {
        return $this->hasMany(CourseClass::class, 'syllabus_id');
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
        return ($this->course?->school_id) !== null ? (int) ($this->course?->school_id) : null;
    }
}
