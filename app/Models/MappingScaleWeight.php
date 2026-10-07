<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MappingScaleWeight extends Model
{
    protected $fillable = [
        'school_id',
        'level',
        'weight_percent',
    ];

    /**
     * Mức I/R/M/A dùng cho một trường: dòng riêng của trường ĐÈ lên dòng mặc định
     * (`school_id NULL`). Trường không khai riêng ⇒ dùng mặc định, y như trước.
     *
     * @return \Illuminate\Support\Collection<string,mixed> level → weight
     */
    public static function forSchool(?int $schoolId): \Illuminate\Support\Collection
    {
        return static::query()
            ->where(fn ($q) => $q->whereNull('school_id')
                ->when($schoolId, fn ($w) => $w->orWhere('school_id', $schoolId)))
            ->orderByRaw('CASE WHEN school_id IS NULL THEN 0 ELSE 1 END')
            ->pluck('weight_percent', 'level');
    }

    protected function casts(): array
    {
        return [
            'weight_percent' => 'decimal:2',
        ];
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
