<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pi extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'plo_id',
        'code',
        'description',
        'is_dummy',
        'weight',
    ];

    protected function casts(): array
    {
        return [
            'is_dummy' => 'boolean',
            'weight' => 'decimal:4',
        ];
    }

    public function plo(): BelongsTo
    {
        return $this->belongsTo(Plo::class);
    }

    public function cloMappings(): HasMany
    {
        return $this->hasMany(CloPiMapping::class);
    }

    public function studentResults(): HasMany
    {
        return $this->hasMany(StudentPiResult::class);
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
        return ($this->plo?->program?->school_id) !== null ? (int) ($this->plo?->program?->school_id) : null;
    }
}
