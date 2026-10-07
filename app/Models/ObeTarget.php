<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mục tiêu mức đạt (%) theo từng PLO/PI cho 1 Trường (tenant).
 * Tham số kiểm định thuộc trường — fallback về global khi không khai.
 */
class ObeTarget extends Model
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'school_id', 'target_type', 'plo_id', 'pi_id', 'target_percent', 'note',
    ];

    protected function casts(): array
    {
        return ['target_percent' => 'decimal:2'];
    }

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function plo(): BelongsTo { return $this->belongsTo(Plo::class); }
    public function pi(): BelongsTo { return $this->belongsTo(Pi::class); }

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
