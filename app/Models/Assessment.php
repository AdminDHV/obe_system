<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assessment extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'syllabus_id',
        'name',
        'assessment_category',
        'weight_percent',
        'is_rubric_based',
    ];

    protected function casts(): array
    {
        return [
            'weight_percent' => 'decimal:2',
            'is_rubric_based' => 'boolean',
        ];
    }

    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(CourseSyllabus::class, 'syllabus_id');
    }

    public function cloMappings(): HasMany
    {
        return $this->hasMany(AssessmentCloMapping::class);
    }

    public function rubric(): HasOne
    {
        return $this->hasOne(Rubric::class);
    }

    public function studentGrades(): HasMany
    {
        return $this->hasMany(StudentGrade::class);
    }

    /**
     * CLO mà thành phần đánh giá này đo lường — hợp nhất 2 nguồn:
     *  - `assessment_clo_mappings` (map thủ công, cho thành phần thường)
     *  - tiêu chí của Rubric (`rubric_criteria.clo_id`) nếu là thành phần rubric-based
     * Engine CLO/PI đọc qua đây để không phải nhập CLO hai lần với thành phần rubric.
     *
     * @return \Illuminate\Support\Collection<int,int>
     */
    public function mappedCloIds(): \Illuminate\Support\Collection
    {
        $fromMappings = $this->cloMappings->pluck('clo_id');
        $fromRubric = $this->rubric?->criteria->pluck('clo_id') ?? collect();

        return $fromMappings->merge($fromRubric)->unique()->values();
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
        return ($this->syllabus?->course?->school_id) !== null ? (int) ($this->syllabus?->course?->school_id) : null;
    }
}
