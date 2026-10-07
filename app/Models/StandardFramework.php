<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StandardFramework extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function plos()
    {
        return $this->belongsToMany(Plo::class, 'framework_plo_mappings', 'framework_id', 'plo_id');
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
