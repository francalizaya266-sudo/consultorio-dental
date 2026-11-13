<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * La dirección en la web (slug) sale sola del nombre cuando no se escribe: solo hace falta pedirla a lo que
 * de verdad aparece en la página.
 *
 * @mixin Model
 */
trait HasWebSlug
{
    public static function bootHasWebSlug(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('slug'))) {
                $model->setAttribute('slug', static::freeSlug((string) $model->getAttribute('name')));
            }
        });
    }

    /** La dirección que sale del nombre; si ya la usa otro (aunque esté borrado), con -2, -3… */
    public static function freeSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::lower(Str::random(6));
        $taken = fn (string $slug): bool => static::query()->withoutGlobalScopes()->where('slug', $slug)->exists();

        $slug = $base;

        for ($number = 2; $taken($slug); $number++) {
            $slug = $base.'-'.$number;
        }

        return $slug;
    }
}
