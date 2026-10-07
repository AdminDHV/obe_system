<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Trường (tenant) — gốc phân tách dữ liệu đa-trường.
 * Trường → Khoa → Ngành → Khóa → … . Mã (ngành/môn/MSSV/lớp) unique TRONG 1 trường.
 */
class School extends Model
{
    use SoftDeletes;
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'code', 'name', 'short_name', 'contact_name', 'contact_email', 'contact_phone', 'is_active', 'obe_method',
        'direct_weight', 'indirect_weight',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'direct_weight' => 'decimal:4',
            'indirect_weight' => 'decimal:4',
        ];
    }

    public function departments(): HasMany { return $this->hasMany(Department::class); }
    public function programs(): HasMany { return $this->hasMany(Program::class); }
    public function courses(): HasMany { return $this->hasMany(Course::class); }
    public function students(): HasMany { return $this->hasMany(Student::class); }
    public function cohorts(): HasMany { return $this->hasMany(Cohort::class); }
    public function courseClasses(): HasMany { return $this->hasMany(CourseClass::class); }

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
