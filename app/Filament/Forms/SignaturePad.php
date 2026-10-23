<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\Field;

/**
 * Firma con el dedo (o el mouse) en la pantalla. Guarda la imagen como PNG en base64;
 * el servicio la valida y la vuelve a generar antes de guardarla.
 */
class SignaturePad extends Field
{
    protected string $view = 'filament.forms.signature-pad';
}
