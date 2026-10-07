<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trọng số của một PI trong PLO, ĐÓNG BĂNG THEO KHÓA.
 * Không có dòng cho khóa nào thì engine dùng `pis.weight` (mặc định của ngành).
 */
class CohortPiWeight extends Model
{
    protected $fillable = ['cohort_id', 'pi_id', 'weight'];

    protected function casts(): array
    {
        return ['weight' => 'decimal:4'];
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function pi(): BelongsTo
    {
        return $this->belongsTo(Pi::class);
    }
}
