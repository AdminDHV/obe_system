<?php

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'department_id',
    'school_id',
    'staff_code',
    'title_prefix',
    'full_name',
    'position',
    'email',
    'password',
    'phone',
    'avatar',
    'employment_status',
    'contract_type',
    'joined_date',
    'contract_end_date',
    'is_active',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements BelongsToSchool, FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;
    use \App\Models\Concerns\Auditable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole('super_admin');
    }

    protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'joined_date' => 'date',
            'contract_end_date' => 'date',
            'last_login_at' => 'datetime',
        ];
    }

    public function getNameAttribute(): string
    {
        return $this->full_name ?? '';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Operator toàn hệ thống (được chuyển trường tự do). Chỉ super_admin.
     * Mọi role khác bị kẹp cứng vào `school_id` — xem App\Support\WorkingSchool.
     */
    public function isGlobalOperator(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function courseClasses(): HasMany
    {
        return $this->hasMany(CourseClass::class, 'lecturer_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function fileBatches(): HasMany
    {
        return $this->hasMany(FileBatch::class);
    }

    public function reportSnapshots(): HasMany
    {
        return $this->hasMany(ReportSnapshot::class);
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
