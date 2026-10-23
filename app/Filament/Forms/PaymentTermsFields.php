<?php

namespace App\Filament\Forms;

use App\Enums\PlanPaymentMode;
use App\Exceptions\ClinicalException;
use App\Services\Clinical\TreatmentPlanService;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Inicial y cuotas, iguales al armar un paquete y al firmar un plan. Se pone el número de cuotas
 * (el resto se reparte en partes iguales) o la cuota mensual (y el sistema calcula cuántas son).
 */
final class PaymentTermsFields
{
    /**
     * @param  Closure(Get): float  $total  El total que se paga en cuotas.
     * @return list<TextInput|DatePicker>
     */
    public static function make(Closure $total, bool $withFirstDueDate = false): array
    {
        $rest = fn (Get $get): float => round($total($get) - (float) $get('down_payment'), 2);
        $countFromMonthly = function (Get $get, Set $set) use ($rest): void {
            if ((float) $get('monthly_amount') > 0) {
                $set('installments', TreatmentPlanService::installmentsFor($rest($get), (float) $get('monthly_amount')) ?: null);
            }
        };

        return array_values(array_filter([
            TextInput::make('down_payment')
                ->label('Inicial')
                ->prefix('S/')
                ->numeric()
                ->minValue(0)
                ->live(onBlur: true)
                ->afterStateUpdated($countFromMonthly),
            TextInput::make('installments')
                ->label('Número de cuotas')
                ->integer()
                ->minValue(1)
                ->maxValue(TreatmentPlanService::MAX_INSTALLMENTS)
                ->required()
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get, $total): void {
                    try {
                        TreatmentPlanService::schedule($total($get), PlanPaymentMode::Installments, [
                            'down_payment' => $get('down_payment'),
                            'installments' => $value,
                            'monthly_amount' => $get('monthly_amount'),
                            'first_due_on' => $get('first_due_on'),
                        ], today());
                    } catch (ClinicalException $e) {
                        $fail($e->getMessage());
                    }
                })
                ->live(onBlur: true)
                // Al elegir cuántas cuotas, el resto se reparte en partes iguales.
                ->afterStateUpdated(fn (Set $set) => $set('monthly_amount', null)),
            TextInput::make('monthly_amount')
                ->label('Cuota mensual')
                ->prefix('S/')
                ->numeric()
                ->minValue(1)
                ->placeholder(function (Get $get) use ($rest): ?string {
                    $count = (int) $get('installments');

                    return $count > 0 && $rest($get) > 0 ? number_format(TreatmentPlanService::evenInstallment($rest($get), $count), 2, '.', '') : null;
                })
                ->helperText('Si la pones, se calcula cuántas cuotas son.')
                ->live(onBlur: true)
                ->afterStateUpdated($countFromMonthly),
            $withFirstDueDate
                ? DatePicker::make('first_due_on')
                    ->label('Primera cuota')
                    ->minDate(today())
                    ->required()
                    ->live()
                : null,
        ]));
    }
}
