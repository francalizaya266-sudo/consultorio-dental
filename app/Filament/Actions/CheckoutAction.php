<?php

namespace App\Filament\Actions;

use App\Enums\AppointmentStatus;
use App\Filament\Forms\PaymentFields;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Patients\PatientInsights;
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
use Filament\Schemas\Components\View;
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
            ->modalSubmitAction(fn (Action $action) => $action->label('Cobrar')->color('primary'))
            ->modalWidth('3xl')
            ->fillForm(function (Appointment $record) {
                $billing = app(BillingService::class);
                // Si venció una cuota de su plan y aún no se cargó, entra en lo que se cobra hoy.
                $billing->chargeDueInstallments($record->patient, self::user());

                $lines = $billing->chargesFor($record);
                $balance = $record->patient->balance();
                $previous = round($balance - $lines->sum(fn (Charge $c) => $c->net()), 2);

                return [
                    'previous_items' => $billing->unpaidCharges($record->patient)
                        ->reject(fn (array $row) => $row['charge']->appointment_id === $record->getKey())
                        ->map(fn (array $row) => $row['charge']->description.' '.Money::format($row['due']))
                        ->implode(', '),
                    'lines' => $lines->map(fn (Charge $c) => [
                        'id' => $c->getKey(),
                        'description' => $c->description,
                        'amount' => (float) $c->amount,
                        'discount' => (float) $c->discount,
                    ])->all(),
                    'previous' => $previous,
                    'paid_here' => round((float) $record->payments()->whereNull('voided_at')->sum('amount'), 2),
                    'amount' => $balance > 0 ? $balance : null,
                    'method' => $record->patient->usualPaymentMethod()->value,
                    'upcoming' => self::upcomingInstallment($record),
                    'paid_at' => now()->toDateTimeString(),
                ];
            })
            ->schema([
                Hidden::make('previous'),
                Hidden::make('previous_items'),
                Hidden::make('paid_here'),
                Hidden::make('upcoming'),
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
                View::make('filament.forms.total-to-pay')
                    ->viewData(fn (Get $get) => self::totalToPay($get)),
                Text::make(fn (Get $get) => $get('upcoming'))
                    ->color('warning')
                    ->visible(fn (Get $get) => filled($get('upcoming'))),
                ...PaymentFields::make(amountRequired: false),
                // Se paga hoy: si la caja de hoy ya se cerró, el pago espera a que la administradora la reabra.
                Hidden::make('paid_at')
                    ->dehydrated(false)
                    ->rule(fn () => RegisterPaymentAction::openDay(), fn (Get $get) => (float) $get('amount') > 0),
            ])
            ->action(function (array $data, Appointment $record) {
                $billing = app(BillingService::class);
                $user = self::user();

                $payment = DB::transaction(function () use ($billing, $data, $record, $user) {
                    $billing->reviseCharges($record, array_values($data['lines'] ?? []), $user);

                    return (float) ($data['amount'] ?? 0) > 0 ? $billing->registerPayment($record->patient, $data, $user, $record) : null;
                });

                if ($payment) {
                    RegisterPaymentAction::notifyRegistered($payment);

                    return;
                }

                // Sin pago: solo se corrigió lo de la cita.
                Notification::make()
                    ->success()
                    ->title('Cobro guardado')
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

    /** Al corregir un precio, el monto se ajusta solo al nuevo total. */
    private static function refreshAmount(Get $get, Set $set, string $path = ''): void
    {
        $total = self::total($get, $path);

        $set($path.'amount', $total > 0 ? $total : null);
    }

    /**
     * Lo que se le cobra hoy, y de dónde sale cuando no es solo lo de esta cita: lo que ya pagó por ella y lo que
     * debía (o tenía a favor) de antes. Lo ya pagado por la cita no se cuenta como saldo a favor.
     *
     * @return array{total: string, note: string|null}
     */
    private static function totalToPay(Get $get): array
    {
        $paidHere = (float) $get('paid_here');
        $before = round((float) $get('previous') + $paidHere, 2);

        return [
            'total' => Money::format(max(0, self::total($get))),
            'note' => collect([
                $paidHere > 0 ? 'Ya pagó '.Money::format($paidHere).' en esta cita' : null,
                match (true) {
                    $before > 0 => 'Incluye el saldo anterior de '.Money::format($before).(filled($get('previous_items')) ? ' ('.$get('previous_items').')' : ''),
                    $before < 0 => 'Ya descuenta los '.Money::format(-$before).' que tenía a favor',
                    default => null,
                },
            ])->filter()->implode(' · ') ?: null,
        ];
    }

    /** Si una cuota de su plan vence en los próximos días: puede aprovechar y pagarla hoy. */
    private static function upcomingInstallment(Appointment $record): ?string
    {
        $next = app(PatientInsights::class)->upcomingInstallment($record->patient, today()->addDays(7));

        return $next ? 'El '.$next['on']->translatedFormat('l j \d\e F').' vence su cuota de '.Money::format($next['due']).': si quiere, la puede pagar hoy.' : null;
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
