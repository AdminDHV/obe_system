<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    /** Bậc đào tạo — quyết định ngưỡng đạt theo quy chế (xem ObeConfig::LEVEL_DEFAULTS). */
    public const LEVEL_DAI_HOC = 'dai_hoc';
    public const LEVEL_THAC_SI = 'thac_si';

    /** @var array<string,string> */
    public const LEVELS = [
        self::LEVEL_DAI_HOC => 'Đại học',
        self::LEVEL_THAC_SI => 'Thạc sĩ',
    ];

    protected $fillable = [
        'school_id',
        'department_id',
        'code',
        'name',
        'level',
        'required_credits',
    ];

    protected $casts = [
        'required_credits' => 'integer',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function cohorts(): HasMany
    {
        return $this->hasMany(Cohort::class);
    }

    public function plos(): HasMany
    {
        return $this->hasMany(Plo::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'program_courses')
            ->withPivot('is_mandatory', 'curriculum_group_id');
    }

    public function curriculumGroups(): HasMany
    {
        return $this->hasMany(CurriculumGroup::class)->orderBy('sort_order')->orderBy('code');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
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
