<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentCloResult extends Model
{
    protected $fillable = [
        'student_id',
        'class_id',
        'clo_id',
        'achieved_score',
    ];

    protected function casts(): array
    {
        return [
            'achieved_score' => 'decimal:4',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(CourseClass::class, 'class_id');
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
}
