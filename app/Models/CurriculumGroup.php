<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Một khối học phần trong chương trình đào tạo.
 *
 *   kind = mandatory  → mọi học viên của ngành đều học (khối đại cương + cơ sở + đề án…)
 *   kind = elective   → học viên CHỌN nhóm này (hướng chuyên ngành); chỉ ai chọn mới tính vào
 *                       lộ trình của họ.
 *
 * `required_credits` là số tín chỉ phải tích lũy trong nhóm, KHÔNG phải số môn — nhóm có thể mở
 * 5 môn mà chỉ yêu cầu 4.
 */
class CurriculumGroup extends Model implements BelongsToSchool
{
    use \App\Models\Concerns\Auditable;

    public const KIND_MANDATORY = 'mandatory';

    public const KIND_ELECTIVE = 'elective';

    protected $fillable = [
        'school_id',
        'program_id',
        'code',
        'name',
        'kind',
        'required_credits',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'required_credits' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** Học phần đã xếp vào nhóm này (qua chính bảng program_courses). */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'program_courses', 'curriculum_group_id', 'course_id')
            ->withPivot('is_mandatory', 'program_id');
    }

    /** Học viên đã chọn hướng này. */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'curriculum_group_student')->withTimestamps();
    }

    public function isElective(): bool
    {
        return $this->kind === self::KIND_ELECTIVE;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
        static::saving(function ($model) {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /** Mang thẳng school_id. */
    public function schoolId(): ?int
    {
        return ($this->school_id) !== null ? (int) ($this->school_id) : null;
    }
}
