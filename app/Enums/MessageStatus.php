<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

/** Estado del aviso. */
enum MessageStatus: string implements HasLabel, HasColor
{
    case Pending = 'pending';
    case Opened = 'opened';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Por enviar',
            self::Opened => 'Abierto en WhatsApp',
            self::Sent => 'Enviado',
            self::Cancelled => 'Descartado',
            self::Failed => 'Falló',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Opened => 'info',
            self::Sent => 'success',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }
}
