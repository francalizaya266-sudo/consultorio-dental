<?php

namespace App\Support;

use App\Enums\DiscountType;
use App\Models\Campaign;
use App\Models\TreatmentPackage;

/** Cómo se anuncia una promoción en la web. */
final class CampaignText
{
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

    /** El paquete que ofrece la campaña, mientras se siga ofreciendo. */
    public static function package(Campaign $campaign): ?TreatmentPackage
    {
        $package = $campaign->package;

        return $package && ! $package->trashed() && $package->is_active ? $package : null;
    }

    /** Hasta cuándo vale, en palabras: "Hasta el 30 de noviembre". */
    public static function until(Campaign $campaign): string
    {
        return 'Hasta el '.$campaign->ends_at->translatedFormat('j \d\e F');
    }
}
