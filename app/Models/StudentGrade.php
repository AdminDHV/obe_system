<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGrade extends Model
{
    // Ghi vết "ai sửa điểm, khi nào" (gồm cả import qua queue nhờ AuditActor).
    // Chỉ StudentGrade — KHÔNG audit Student*Result (dẫn xuất, tính lại liên tục = nhiễu;
    // đã có MeasurementRun theo dõi việc tính lại).
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'student_id',
        'class_id',
        'assessment_id',
        'score',
        'is_absent',
        'is_legacy_data',
        'attempt_number',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:4',
            'is_absent' => 'boolean',
            'is_legacy_data' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function courseClass(): BelongsTo
    {
        return $this->belongsTo(CourseClass::class, 'class_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * Lớp mà học viên VẮNG THI CUỐI KỲ và chưa có điểm thi lại — học viên đứng ngoài cỡ mẫu của môn
     * ở lớp đó (lớp 40 bạn, 2 bạn VT thì đo trên 38). Có điểm thi lại thì dòng VT được thay bằng điểm.
     *
     * @param array<int,int> $studentIds
     * @param array<int,int>|null $classIds null = mọi lớp
     * @return array<int, array<int,true>> class_id → student_id → true
     */
    public static function vangThiCuoiKy(array $studentIds = [], ?array $classIds = null): array
    {
        $rows = self::query()
            ->where('is_absent', true)
            ->whereNull('score')
            ->whereHas('assessment', fn ($q) => $q->where('assessment_category', 'final'))
            ->when($studentIds !== [], fn ($q) => $q->whereIn('student_id', $studentIds))
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
            ->get(['class_id', 'student_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->class_id][(int) $row->student_id] = true;
        }

        return $out;
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
