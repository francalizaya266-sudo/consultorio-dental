<?php

namespace App\Filament\Forms;

use App\Enums\PaymentMethod;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;

/** Monto, medio de pago y n.º de operación: iguales al cobrar una cita o al registrar un pago suelto. */
final class PaymentFields
{
    /** @return list<TextInput|ToggleButtons> */
    public static function make(bool $amountRequired = true): array
    {
        return [
            TextInput::make('amount')
                ->label('Paga ahora')
                ->prefix('S/')
                ->numeric()
                ->minValue($amountRequired ? 0.01 : 0)
                ->maxValue(999999)
                ->required($amountRequired)
                ->live(onBlur: true),
            ToggleButtons::make('method')
                ->label('Medio de pago')
                ->options(PaymentMethod::class)
                ->default(PaymentMethod::Cash->value)
                ->inline()
                ->live()
                ->required(fn (Get $get) => (float) $get('amount') > 0),
            TextInput::make('reference')
                ->label('N.º de operación')
                ->maxLength(50)
                ->hidden(fn (Get $get) => in_array($get('method'), [null, PaymentMethod::Cash->value, PaymentMethod::Cash], true)),
        ];
    }
}
