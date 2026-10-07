<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeasurementSnapshotStudent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'student_id', 'student_code', 'full_name', 'class_group',
        'track', 'track_pending',
        'credits_earned', 'required_credits', 'completion_percent', 'on_track', 'counted',
    ];

    protected function casts(): array
    {
        return ['on_track' => 'boolean', 'counted' => 'boolean', 'track_pending' => 'boolean'];
    }
}
