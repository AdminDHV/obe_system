<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kết quả khảo sát theo từng PLO (tổng hợp): số phản hồi + điểm Likert TB +
 * tỉ lệ đạt 0..1 = (mean−1)/(likert_max−1).
 */
class SurveyPloResult extends Model
{
    protected $fillable = [
        'survey_instrument_id', 'plo_id', 'respondents', 'mean_likert', 'attainment_ratio',
    ];

    protected function casts(): array
    {
        return [
            'respondents' => 'integer',
            'mean_likert' => 'decimal:4',
            'attainment_ratio' => 'decimal:4',
        ];
    }

    public function instrument(): BelongsTo { return $this->belongsTo(SurveyInstrument::class, 'survey_instrument_id'); }
    public function plo(): BelongsTo { return $this->belongsTo(Plo::class); }
}
