<?php

namespace App\Support;

use App\Enums\DiscountType;
use App\Models\Campaign;

/** Cómo se anuncia una promoción en la web. */
final class CampaignText
{
    /** "20 % de descuento", "S/ 50 de descuento" o null. */
    public static function discount(Campaign $campaign): ?string
    {
        if ($campaign->discount_value === null || (float) $campaign->discount_value <= 0) {
            return null;
        }

        return match ($campaign->discount_type) {
            DiscountType::Percent => rtrim(rtrim(number_format((float) $campaign->discount_value, 2), '0'), '.')."\u{00A0}% de descuento",
            DiscountType::Amount => Money::short($campaign->discount_value).' de descuento',
            default => null,
        };
    }

    /** Hasta cuándo vale, en palabras: "Hasta el 30 de noviembre". */
    public static function until(Campaign $campaign): string
    {
        return 'Hasta el '.$campaign->ends_at->translatedFormat('j \d\e F');
    }
}
