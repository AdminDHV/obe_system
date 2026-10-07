<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model implements BelongsToSchool
{
    protected $table = 'course_class_student';

    public $timestamps = false;

    protected $fillable = [
        'class_id',
        'student_id',
        'enrolled_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(CourseClass::class, 'class_id');
    }

    /** Truy ngược quan hệ vì bảng này không mang school_id. */
    public function schoolId(): ?int
    {
        return ($this->courseClass?->school_id) !== null ? (int) ($this->courseClass?->school_id) : null;
    }
}
