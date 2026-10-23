<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Canal de WhatsApp. */
enum MessageChannel: string implements HasLabel
{
    case ClickToChat = 'click_to_chat';
    case CloudApi = 'cloud_api';

    public function getLabel(): string
    {
        return match ($this) {
            self::ClickToChat => 'WhatsApp del consultorio',
            self::CloudApi => 'API oficial de WhatsApp',
        };
    }
}
