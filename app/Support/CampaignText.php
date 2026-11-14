<?php

namespace App\Support;

use App\Enums\DiscountType;
use App\Models\Campaign;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use Illuminate\Support\Str;

/** Cómo se anuncia una promoción en la web. */
final class CampaignText
{
    /** Cómo se paga: siempre en el consultorio, nunca en la web. */
    public const HOW_TO_PAY = 'Pagas en el consultorio con Yape, Plin, tarjeta o efectivo.';

    /** "20 % de descuento", "S/ 50 de descuento", "2x1 · ahorras S/ 300" (si ofrece un paquete) o null. */
    public static function discount(Campaign $campaign): ?string
    {
        $discount = (float) $campaign->discount_value > 0 ? match ($campaign->discount_type) {
            DiscountType::Percent => rtrim(rtrim(number_format((float) $campaign->discount_value, 2), '0'), '.')."\u{00A0}% de descuento",
            DiscountType::Amount => Money::short($campaign->discount_value).' de descuento',
            default => null,
        } : null;

        if ($discount !== null || ! $package = self::package($campaign)) {
            return $discount;
        }

        $text = collect([
            $package->for_two ? '2x1' : null,
            $package->savings() > 0 ? 'ahorras '.Money::short($package->savings()) : null,
        ])->filter()->implode(' · ');

        return $text === '' ? null : ucfirst($text);
    }

    /**
     * El descuento para mostrarlo junto al nombre de la promoción: null cuando el nombre ya lo dice
     * ("Blanqueamiento 20% de descuento"), para no anunciarlo dos veces seguidas.
     */
    public static function discountBesideName(Campaign $campaign): ?string
    {
        $discount = self::discount($campaign);

        if ($discount === null) {
            return null;
        }

        // Se compara la cifra sin espacios ni mayúsculas: "20 %" y "20%", "S/ 50" y "s/50" son lo mismo.
        $squash = fn (string $text): string => (string) preg_replace('/[\s\x{00A0}]+/u', '', mb_strtolower($text));
        $name = $squash($campaign->name);
        $figure = $squash(Str::before($discount, ' de descuento'));

        // Un porcentaje en el nombre siempre es el descuento; un monto puede ser el precio, salvo que diga "descuento".
        $nameSaysIt = str_contains($name, $figure)
            && ($campaign->discount_type === DiscountType::Percent || Str::contains($name, ['descuento', 'dscto', 'dcto']));

        return $nameSaysIt ? null : $discount;
    }

    /** El paquete que ofrece la campaña, mientras se siga ofreciendo. */
    public static function package(Campaign $campaign): ?TreatmentPackage
    {
        $package = $campaign->package;

        return $package && ! $package->trashed() && $package->is_active ? $package : null;
    }

    /**
     * El precio de la cita en una línea: "Precio con la promoción Sonrisa de verano: desde S/ 120 (normal desde S/ 150)."
     * o "Precio: desde S/ 150.". Null si el tratamiento no muestra su precio.
     */
    public static function price(Treatment $treatment, ?Campaign $campaign = null): ?string
    {
        $webPrice = $treatment->webPrice();
        $withCampaign = $campaign?->priceFor($treatment);

        return match (true) {
            $withCampaign !== null => 'Precio con la promoción '.$campaign->name.': '.($withCampaign > 0 ? 'desde '.Money::short($withCampaign) : 'gratis').' (normal '.$webPrice.').',
            $webPrice !== null => 'Precio: '.Str::lcfirst($webPrice).'.',
            default => null,
        };
    }

    /** Hasta cuándo vale, en palabras: "Hasta el 30 de noviembre". */
    public static function until(Campaign $campaign): string
    {
        return 'Hasta el '.$campaign->ends_at->translatedFormat('j \d\e F');
    }
}
