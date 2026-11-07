<?php

namespace App\Filament\Actions;

use App\Enums\PaymentMethod;
use App\Filament\Forms\PaymentFields;
use App\Models\DailyClosing;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Registrar un pago: el mismo formulario en Caja, en la ficha del paciente, en las listas de cobro y al
 * terminar la atención. Dice lo que debe y por qué, propone el saldo y deja la constancia a un clic.
 */
final class RegisterPaymentAction
{
    /**
     * @param  Closure|null  $patient  Devuelve el paciente (puede recibir $record). Sin él, se elige en el formulario.
     * @param  Closure|null  $amount  Lo que se propone cobrar (puede recibir $record). Por defecto, lo que debe.
     */
    public static function make(?Closure $patient = null, ?Closure $amount = null): Action
    {
        $resolve = fn (Action $action): ?Patient => $patient ? $action->evaluate($patient) : null;

        return Action::make('registerPayment')
            ->label('Registrar pago')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color(fn (Action $action) => ($resolve($action)?->balance() ?? 0) > 0 ? 'success' : 'gray')
            ->visible(fn () => self::user()->can('create', Payment::class))
            ->modalHeading(fn (Action $action) => ($found = $resolve($action)) ? 'Cobrar a '.$found->full_name : 'Registrar pago')
            ->modalSubmitAction(fn (Action $submit) => $submit->label('Registrar pago')->color('primary'))
            ->modalWidth('lg')
            ->fillForm(function (Action $action) use ($resolve, $amount) {
                $found = $resolve($action);
                $proposed = $amount ? $action->evaluate($amount) : max(0, $found?->balance() ?? 0);

                return [
                    'patient_id' => $found?->getKey(),
                    'amount' => $proposed > 0 ? round($proposed, 2) : null,
                    'method' => ($found?->usualPaymentMethod() ?? PaymentMethod::Cash)->value,
                    'paid_at' => now()->format('Y-m-d H:i'),
                ];
            })
            ->schema([
                Select::make('patient_id')
                    ->label('Paciente')
                    ->placeholder('Busca por nombre, DNI o celular')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search) => Patient::query()
                        ->search($search)
                        ->with('account')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Patient $found) => [$found->getKey() => $found->selectLabel()]))
                    ->getOptionLabelUsing(fn ($value) => Patient::with('account')->find($value)?->selectLabel())
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state) {
                        if (! $found = Patient::find($state)) {
                            return;
                        }

                        $balance = $found->balance();
                        $set('amount', $balance > 0 ? $balance : null);
                        $set('method', $found->usualPaymentMethod()->value);
                    })
                    ->hidden($patient !== null),
                Text::make(fn (Get $get) => self::owed(Patient::find($get('patient_id'))))
                    ->visible(fn (Get $get) => filled($get('patient_id'))),
                ...PaymentFields::make(),
                DateTimePicker::make('paid_at')
                    ->label('Fecha')
                    ->seconds(false)
                    ->maxDate(now()->endOfDay())
                    ->required()
                    ->rule(fn () => self::openDay()),
                TextInput::make('notes')
                    ->label('Nota')
                    ->placeholder('Por ejemplo: cuota 2 de ortodoncia')
                    ->maxLength(255),
            ])
            ->action(function (array $data, Action $action) use ($resolve) {
                $found = $resolve($action) ?? Patient::findOrFail($data['patient_id']);
                $payment = app(BillingService::class)->registerPayment($found, $data, self::user());

                self::notifyRegistered($payment);
            });
    }

    /** "Pago registrado" (o "Devolución registrada"), con el saldo que quedó y la constancia para imprimir o enviar. */
    public static function notifyRegistered(Payment $payment): void
    {
        Notification::make()
            ->success()
            ->title((float) $payment->amount < 0 ? 'Devolución registrada' : 'Pago registrado')
            ->body('Saldo de '.$payment->patient->full_name.': '.Money::balance($payment->patient->balance()))
            ->actions([
                Action::make('receipt')
                    ->label('Ver constancia')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(self::receiptUrl($payment), shouldOpenInNewTab: true)
                    ->button(),
            ])
            ->send();
    }

    /** La hoja de la constancia, para imprimirla o enviarla por WhatsApp. */
    public static function receiptUrl(Payment $payment): string
    {
        return Filament::getPanel('admin')->route('print.receipt', ['payment' => $payment]);
    }

    /** Un pago no entra en un día cuya caja ya se cerró (salvo que lo registre la administradora). */
    public static function openDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (filled($value) && DailyClosing::locksFor(self::user(), $value)) {
                $fail(DailyClosing::LOCKED);
            }
        };
    }

    /** "Debe S/ 120.00: Curación con resina S/ 120.00 (5/11)." */
    private static function owed(?Patient $patient): string
    {
        if (! $patient) {
            return '';
        }

        $balance = $patient->balance();

        if ($balance <= 0) {
            return $balance < 0 ? 'Tiene a favor '.Money::format(-$balance).': lo que pague queda a cuenta.' : 'Está al día: lo que pague queda a cuenta.';
        }

        $owed = app(BillingService::class)->unpaidCharges($patient);
        $detail = $owed->take(4)
            ->map(fn (array $row) => $row['charge']->description.' '.Money::format($row['due']).' ('.$row['charge']->charged_at->format('j/n').')')
            ->implode(' · ');

        return 'Debe '.Money::format($balance).': '.$detail.($owed->count() > 4 ? ' y '.($owed->count() - 4).' más' : '').'.';
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
