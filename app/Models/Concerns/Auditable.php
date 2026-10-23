<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Registra cada alta, cambio, eliminación o restauración del modelo
 * con fecha, usuario y valores anteriores/nuevos.
 *
 * @mixin Model
 */
trait Auditable
{
    /** Atributos que nunca se guardan en la auditoría. */
    protected static array $auditExcluded = ['created_at', 'updated_at', 'password', 'remember_token'];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAudit('created', [], $model->getAttributes()));

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            $original = array_intersect_key($model->getOriginal(), $changes);

            $model->writeAudit('updated', $original, $changes);
        });

        static::deleted(fn (Model $model) => $model->writeAudit('deleted', $model->getAttributes(), []));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->writeAudit('restored', [], ['deleted_at' => null]));
        }
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('id');
    }

    protected function writeAudit(string $event, array $old, array $new): void
    {
        $excluded = array_merge(static::$auditExcluded, $this->getHidden());
        $old = array_diff_key($old, array_flip($excluded));
        $new = array_diff_key($new, array_flip($excluded));

        if ($event === 'updated' && $old === [] && $new === []) {
            return;
        }

        $request = app()->runningInConsole() ? null : request();

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'url' => $request ? substr($request->fullUrl(), 0, 255) : null,
        ]);
    }
}
