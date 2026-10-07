<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Đợt khảo sát đo GIÁN TIẾP (1 nhóm bên liên quan) cho 1 ngành (+khóa).
 */
class SurveyInstrument extends Model
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'school_id', 'program_id', 'cohort_id', 'stakeholder', 'label', 'likert_max',
    ];

    protected function casts(): array
    {
        return ['likert_max' => 'integer'];
    }

    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function program(): BelongsTo { return $this->belongsTo(Program::class); }
    public function cohort(): BelongsTo { return $this->belongsTo(Cohort::class); }
    public function ploResults(): HasMany { return $this->hasMany(SurveyPloResult::class); }

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
