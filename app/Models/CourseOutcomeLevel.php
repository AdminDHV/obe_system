<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một ô của ma trận CTĐT: môn đóng góp PI (hoặc PLO nối thẳng) ở mức I/R/M/A, cho một khóa.
 */
class CourseOutcomeLevel extends Model implements BelongsToSchool
{
    protected $fillable = ['cohort_id', 'course_id', 'pi_id', 'level'];

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function pi(): BelongsTo
    {
        return $this->belongsTo(Pi::class);
    }

    /**
     * Ma trận CTĐT của một khóa.
     *
     * @return array<int, array<int,string>> course_id → (pi_id → I/R/M/A)
     */
    public static function forCohort(int $cohortId): array
    {
        $out = [];
        foreach (self::where('cohort_id', $cohortId)->get(['course_id', 'pi_id', 'level']) as $row) {
            $out[(int) $row->course_id][(int) $row->pi_id] = (string) $row->level;
        }

        return $out;
    }

    public function schoolId(): ?int
    {
        return $this->cohort?->school_id !== null ? (int) $this->cohort->school_id : null;
    }
}
