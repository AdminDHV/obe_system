<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model implements BelongsToSchool
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    /**
     * Mã tra cứu là BÍ MẬT của học viên. Nếu để cơ chế audit chép giá trị vào audit_logs thì
     * bảng nhật ký trở thành danh bạ mã của cả trường — người xem nhật ký sẽ tra được kết quả
     * của bất kỳ ai. Việc cấp/cấp lại vẫn được ghi vết riêng (không kèm mã) trong
     * StudentLookupService.
     */
    protected array $auditExclude = ['lookup_code'];

    protected $fillable = [
        'school_id',
        'cohort_id',
        'class_group',
        'program_id',
        'student_code',
        'citizen_id',
        'full_name',
        'date_of_birth',
        'gender',
        'email',
        'personal_email',
        'phone',
        'permanent_address',
        'contact_address',
        'admission_date',
        'status',
        'avatar',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admission_date' => 'date',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function courseClasses(): BelongsToMany
    {
        return $this->belongsToMany(CourseClass::class, 'course_class_student', 'student_id', 'class_id');
    }

    /** Hướng chuyên ngành (khối tự chọn) học viên đã chọn — quyết định lộ trình riêng của họ. */
    public function curriculumGroups(): BelongsToMany
    {
        return $this->belongsToMany(CurriculumGroup::class, 'curriculum_group_student')->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(StudentGrade::class);
    }

    public function rubricScores(): HasMany
    {
        return $this->hasMany(StudentRubricScore::class);
    }

    public function cloResults(): HasMany
    {
        return $this->hasMany(StudentCloResult::class);
    }

    public function piResults(): HasMany
    {
        return $this->hasMany(StudentPiResult::class);
    }

    public function ploResults(): HasMany
    {
        return $this->hasMany(StudentPloResult::class);
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
