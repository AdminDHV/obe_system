<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RubricCriteria extends Model implements BelongsToSchool
{
    use \App\Models\Concerns\Auditable;

    protected $table = 'rubric_criteria';

    protected $fillable = [
        'rubric_id',
        'class_id',
        'clo_id',
        'question_label',
        'question_type',
        'max_score',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
        ];
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }

    public function clo(): BelongsTo
    {
        return $this->belongsTo(Clo::class);
    }

    public function studentScores(): HasMany
    {
        return $this->hasMany(StudentRubricScore::class);
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
        return ($this->rubric?->assessment?->syllabus?->course?->school_id) !== null ? (int) ($this->rubric?->assessment?->syllabus?->course?->school_id) : null;
    }
}
