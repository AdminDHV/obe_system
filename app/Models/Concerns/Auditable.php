<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\AuditActor;
use Illuminate\Support\Facades\Auth;

/**
 * Records create/update/delete activity to the `audit_logs` table.
 *
 * Actor = user đăng nhập (`Auth::id()`) HOẶC actor được ép qua `AuditActor::runAs()`
 * (dùng cho import chạy trong queue job — quy về người upload file). Không có cả hai
 * (seeder, migrate:fresh, command không set actor) → BỎ QUA để không tạo audit rác và
 * vì `audit_logs.user_id` là NOT NULL.
 */
trait Auditable
{
    /** Attribute keys never worth recording (noise / secrets). */
    protected array $auditExcludeDefault = [
        'id', 'uuid', 'password', 'remember_token', 'created_at', 'updated_at', 'deleted_at',
    ];

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAuditLog('created', null, $model->auditableValues($model->getAttributes()));
        });

        static::updated(function ($model) {
            $new = $model->auditableValues($model->getChanges());
            if (empty($new)) {
                return; // only excluded fields (e.g. timestamps) changed
            }
            $old = [];
            foreach (array_keys($new) as $key) {
                $old[$key] = $model->getOriginal($key);
            }
            $model->writeAuditLog('updated', $old, $new);
        });

        static::deleted(function ($model) {
            $model->writeAuditLog('deleted', $model->auditableValues($model->getAttributes()), null);
        });
    }

    protected function writeAuditLog(string $action, ?array $old, ?array $new): void
    {
        $actorId = Auth::id() ?? AuditActor::id();
        if ($actorId === null) {
            return;
        }

        AuditLog::create([
            'user_id' => $actorId,
            'action' => $action,
            'model_type' => class_basename($this),
            'model_id' => $this->getKey(),
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    protected function auditableValues(array $attributes): array
    {
        $exclude = array_merge($this->auditExcludeDefault, $this->auditExclude ?? []);

        return collect($attributes)->except($exclude)->toArray();
    }
}
