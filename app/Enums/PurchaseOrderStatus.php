<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** En qué va un pedido al proveedor. */
enum PurchaseOrderStatus: string implements HasColor, HasLabel
{
    case Sent = 'sent';
    case Partial = 'partial';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sent => 'Pedido',
            self::Partial => 'Llegó una parte',
            self::Received => 'Recibido',
            self::Cancelled => 'Anulado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sent, self::Partial => 'warning',
            self::Received => 'success',
            self::Cancelled => 'gray',
        };
    }

    /** Todavía falta que llegue algo. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Sent, self::Partial], true);
    }
}
