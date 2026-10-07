<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileBatch extends Model implements BelongsToSchool
{
    protected $fillable = [
        'user_id',
        'school_id',
        'class_id',
        'type',
        'file_path',
        'file_name',
        'total_rows',
        'processed_rows',
        'status',
        'error_log',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'error_log' => 'array',
            'summary' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(CourseClass::class, 'class_id');
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
