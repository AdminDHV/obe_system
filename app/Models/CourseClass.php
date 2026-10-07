<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseClass extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'school_id',
        'syllabus_id',
        'cohort_id',
        'lecturer_id',
        'class_code',
        'academic_year',
        'semester',
        'max_capacity',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'max_capacity' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(CourseSyllabus::class, 'syllabus_id');
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'course_class_student', 'class_id', 'student_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'class_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(StudentGrade::class, 'class_id');
    }

    public function cloResults(): HasMany
    {
        return $this->hasMany(StudentCloResult::class, 'class_id');
    }

    public function fileBatches(): HasMany
    {
        return $this->hasMany(FileBatch::class, 'class_id');
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
        static::deleting(function ($courseClass) {
            if ($courseClass->isForceDeleting()) {
                $criteriaIds = $courseClass->syllabus?->assessments()
                    ->where('is_rubric_based', true)
                    ->get()
                    ->flatMap(fn($a) => $a->rubric ? $a->rubric->criteria->pluck('id') : [])
                    ->toArray() ?? [];

                if (!empty($criteriaIds)) {
                    $studentIds = $courseClass->students()->pluck('students.id')->toArray();
                    if (!empty($studentIds)) {
                        \App\Models\StudentRubricScore::whereIn('student_id', $studentIds)
                            ->whereIn('rubric_criteria_id', $criteriaIds)
                            ->delete();
                    }
                }
            }
        });
    }

    /** Mang thẳng school_id. */
    public function schoolId(): ?int
    {
        return ($this->school_id) !== null ? (int) ($this->school_id) : null;
    }
}
