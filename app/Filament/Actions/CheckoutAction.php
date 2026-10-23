<?php

namespace App\Filament\Actions;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Filament\Forms\PaymentFields;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Cobrar al terminar la cita, en un solo paso: lo cargado por la cita (que el sistema ya
 * armó y recepción puede corregir), el saldo anterior y el pago.
 */
final class CheckoutAction
{
    public static function make(): Action
    {
        return Action::make('checkout')
            ->label('Cobrar')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            // Mientras el paciente deba algo, o si la cita aún no tiene ningún pago.
            ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::Completed
                && self::user()->managesAllAgendas()
                && ($record->patient->balance() > 0 || ! Payment::query()->whereBelongsTo($record)->whereNull('voided_at')->exists()))
            ->authorize(fn () => self::user()->can('create', Charge::class))
            ->modalHeading(fn (Appointment $record) => 'Cobrar a '.$record->patient->full_name)
            ->modalDescription(fn (Appointment $record) => $record->starts_at->translatedFormat('l j \d\e F').' · '.$record->treatment->name.' · '.$record->doctor->name)
            ->modalSubmitAction(fn (Action $action) => $action->label('Guardar cobro')->color('primary'))
            ->modalWidth('3xl')
            ->fillForm(function (Appointment $record) {
                $lines = app(BillingService::class)->chargesFor($record);
                $balance = $record->patient->balance();
                $previous = round($balance - $lines->sum(fn (Charge $c) => $c->net()), 2);

                return [
                    'lines' => $lines->map(fn (Charge $c) => [
                        'id' => $c->getKey(),
                        'description' => $c->description,
                        'amount' => (float) $c->amount,
                        'discount' => (float) $c->discount,
                    ])->all(),
                    'previous' => $previous,
                    'amount' => $balance > 0 ? $balance : null,
                    'method' => PaymentMethod::Cash->value,
                ];
            })
            ->schema([
                Hidden::make('previous'),
                Repeater::make('lines')
                    ->label('Lo de esta cita')
                    ->table([
                        TableColumn::make('Concepto'),
                        TableColumn::make('Precio')->width('9rem'),
                        TableColumn::make('Descuento')->width('9rem'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('description')
                            ->label('Concepto')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('amount')
                            ->label('Precio')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshAmount($get, $set, '../../')),
                        TextInput::make('discount')
                            ->label('Descuento')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                if ((float) $value > (float) $get('amount')) {
                                    $fail('El descuento no puede ser mayor que el precio.');
                                }
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshAmount($get, $set, '../../')),
                    ])
                    ->addActionLabel('Agregar concepto')
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshAmount($get, $set)),
                Text::make(fn (Get $get) => self::summary($get))
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold),
                ...PaymentFields::make(amountRequired: false),
            ])
            ->action(function (array $data, Appointment $record) {
                $billing = app(BillingService::class);
                $user = self::user();

                DB::transaction(function () use ($billing, $data, $record, $user) {
                    $billing->reviseCharges($record, array_values($data['lines'] ?? []), $user);

                    if ((float) ($data['amount'] ?? 0) > 0) {
                        $billing->registerPayment($record->patient, $data, $user, $record);
                    }
                });

                Notification::make()
                    ->success()
                    ->title((float) ($data['amount'] ?? 0) > 0 ? 'Pago registrado' : 'Cobro guardado')
                    ->body('Saldo del paciente: '.Money::balance($record->patient->balance()))
                    ->send();
            });
    }

    /** Saldo anterior más lo de esta cita. $path lleva del campo que cambió a la raíz del formulario. */
    private static function total(Get $get, string $path = ''): float
    {
        $lines = collect($get($path.'lines') ?? [])->sum(fn (array $line) => (float) ($line['amount'] ?? 0) - (float) ($line['discount'] ?? 0));

        return round((float) $get($path.'previous') + $lines, 2);
    }

    /** Al corregir un precio, "Paga ahora" se ajusta solo al nuevo total. */
    private static function refreshAmount(Get $get, Set $set, string $path = ''): void
    {
        $total = self::total($get, $path);

        $set($path.'amount', $total > 0 ? $total : null);
    }

    private static function summary(Get $get): string
    {
        $previous = (float) $get('previous');
        $total = self::total($get);

        return match (true) {
            $previous > 0 => 'Saldo anterior '.Money::format($previous).' · Total a pagar '.Money::format(max(0, $total)),
            $previous < 0 => 'Tenía a favor '.Money::format(-$previous).' · Total a pagar '.Money::format(max(0, $total)),
            default => 'Total a pagar '.Money::format(max(0, $total)),
        };
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
