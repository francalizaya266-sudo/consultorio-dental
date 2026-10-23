<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Cómo nos encontró. */
enum AppointmentOrigin: string implements HasLabel
{
    case Google = 'google';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case WhatsApp = 'whatsapp';
    case Campaign = 'campaign';
    case Referral = 'referral';
    case Recommendation = 'recommendation';
    case WalkIn = 'walk_in';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::WhatsApp => 'WhatsApp',
            self::Campaign => 'Campaña',
            self::Referral => 'Referido',
            self::Recommendation => 'Recomendación',
            self::WalkIn => 'Pasaba por aquí',
            self::Other => 'Otro',
        };
    }
}
