<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Estado del aviso. */
enum MessageStatus: string implements HasColor, HasLabel
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
