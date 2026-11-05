<?php

namespace App\Exceptions;

use RuntimeException;

/** Error del inventario con un mensaje en español simple, listo para mostrar. */
class InventoryException extends RuntimeException
{
    public static function invalidQuantity(): self
    {
        return new self('La cantidad tiene que ser mayor que cero.');
    }

    public static function duplicateSerial(string $serial): self
    {
        return new self("El número de serie {$serial} ya está registrado en este material.");
    }

    public static function orderClosed(): self
    {
        return new self('Este pedido ya se recibió o se anuló.');
    }
}
