<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plo extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'program_id',
        'code',
        'description',
    ];

    protected $appends = ['name'];

    public function getNameAttribute(): string
    {
        return $this->description ?? '';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function pis(): HasMany
    {
        return $this->hasMany(Pi::class);
    }

    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentPloResult::class);
    }

    public function standardFrameworks()
    {
        return $this->belongsToMany(StandardFramework::class, 'framework_plo_mappings', 'plo_id', 'framework_id');
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
        return ($this->program?->school_id) !== null ? (int) ($this->program?->school_id) : null;
    }
}
