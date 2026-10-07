<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementSnapshotOutcome extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'student_code', 'outcome_type', 'outcome_code', 'achieved_score',
        'courses_done', 'courses_planned',
    ];
}
