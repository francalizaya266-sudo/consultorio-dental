<?php

namespace App\Filament\Forms;

use App\Enums\PaymentMethod;
use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

/**
 * Monto, medio de pago y n.º de operación: iguales al cobrar una cita o al registrar un pago suelto.
 * En efectivo se puede anotar con cuánto paga, y el sistema dice el vuelto.
 */
final class PaymentFields
{
    /** @return list<TextInput|ToggleButtons|Text> */
    public static function make(bool $amountRequired = true): array
    {
        return [
            TextInput::make('amount')
                ->label('Monto')
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
            // Solo para la cuenta del vuelto: no se guarda.
            TextInput::make('received')
                ->label('¿Con cuánto paga?')
                ->placeholder('Opcional: para calcular el vuelto')
                ->prefix('S/')
                ->numeric()
                ->minValue(0)
                ->maxValue(999999)
                ->dehydrated(false)
                ->live(debounce: 400)
                ->visible(fn (Get $get) => self::paysInCash($get) && (float) $get('amount') > 0),
            Text::make(fn (Get $get) => self::change($get))
                ->size(TextSize::Large)
                ->weight(FontWeight::Bold)
                ->color(fn (Get $get) => (float) $get('received') < (float) $get('amount') ? 'danger' : 'success')
                ->visible(fn (Get $get) => self::paysInCash($get) && (float) $get('amount') > 0 && filled($get('received'))),
            TextInput::make('reference')
                ->label('N.º de operación')
                ->maxLength(50)
                ->hidden(fn (Get $get) => $get('method') === null || self::paysInCash($get)),
        ];
    }

    private static function paysInCash(Get $get): bool
    {
        return in_array($get('method'), [PaymentMethod::Cash->value, PaymentMethod::Cash], true);
    }

    /** "Vuelto: S/ 20.00", "Paga justo, sin vuelto" o "Faltan S/ 30.00". */
    private static function change(Get $get): string
    {
        $change = round((float) $get('received') - (float) $get('amount'), 2);

        return match (true) {
            $change > 0 => 'Vuelto: '.Money::format($change),
            $change < 0 => 'Faltan '.Money::format(-$change),
            default => 'Paga justo, sin vuelto',
        };
    }
}
