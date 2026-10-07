<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'school_id',
        'code',
        'name',
        'credits',
        'periods',
        'managing_unit',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function syllabi(): HasMany
    {
        return $this->hasMany(CourseSyllabus::class);
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'program_courses')
            ->withPivot('is_mandatory', 'curriculum_group_id');
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_prerequisites', 'course_id', 'required_course_id')
            ->withPivot('requirement_type');
    }

    public function requiredBy(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_prerequisites', 'required_course_id', 'course_id')
            ->withPivot('requirement_type');
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

    /** Mang thẳng school_id. */
    public function schoolId(): ?int
    {
        return ($this->school_id) !== null ? (int) ($this->school_id) : null;
    }
}
