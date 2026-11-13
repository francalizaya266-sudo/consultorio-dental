<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Configuración del consultorio editable desde el panel.
 * Los valores por defecto viven en config/consultorio.php.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use Auditable {
        writeAudit as private writeChange;
    }

    private const CACHE_KEY = 'settings.all';

    /** Lo que el sistema anota solo (cuándo fue la última copia de seguridad): no es un cambio hecho por alguien. */
    private const SYSTEM_STATE = 'backups.';

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return Arr::get(config('consultorio.defaults', []), $key, $default);
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * El historial guarda cada ajuste con su valor de antes y el de después, ya leídos (120, no "120").
     * La primera vez que se guarda una hoja se crean todos sus ajustes a la vez: de esos cuenta solo el que quedó
     * distinto del valor con que viene el sistema.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function writeAudit(string $event, array $old, array $new): void
    {
        if (str_starts_with((string) $this->key, self::SYSTEM_STATE)) {
            return;
        }

        if (! in_array($event, ['created', 'updated'], true)) {
            $this->writeChange($event, $old, $new);

            return;
        }

        $before = $event === 'created' ? Arr::get(config('consultorio.defaults', []), $this->key) : ($old['value'] ?? null);

        if (! self::sameValue($before, $this->value)) {
            $this->writeChange('updated', ['value' => $before], ['value' => $this->value]);
        }
    }

    /** 120 y "120" son el mismo valor; vacío y sin dato, también. */
    private static function sameValue(mixed $before, mixed $after): bool
    {
        if (is_numeric($before) && is_numeric($after)) {
            return (float) $before === (float) $after;
        }

        return (blank($before) && blank($after)) || $before === $after;
    }
}
