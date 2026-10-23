<?php

namespace App\Filament\Actions;

use App\Enums\PaymentMethod;
use App\Filament\Forms\PaymentFields;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Pago suelto desde la ficha del paciente (una cuota, un adelanto), con el saldo a la vista. */
final class RegisterPaymentAction
{
    /** @param  Closure(): Patient  $patient */
    public static function make(Closure $patient): Action
    {
        return Action::make('registerPayment')
            ->label('Registrar pago')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color(fn () => $patient()->balance() > 0 ? 'success' : 'gray')
            ->visible(fn () => self::user()->can('create', Payment::class))
            ->modalHeading(fn () => 'Pago de '.$patient()->full_name)
            ->modalDescription(fn () => 'Saldo actual: '.Money::balance($patient()->balance()))
            ->modalSubmitAction(fn (Action $action) => $action->label('Registrar pago')->color('primary'))
            ->modalWidth('lg')
            ->fillForm(fn () => [
                'amount' => max(0, $patient()->balance()) ?: null,
                'method' => PaymentMethod::Cash->value,
                'paid_at' => now()->format('Y-m-d H:i'),
            ])
            ->schema([
                ...PaymentFields::make(),
                DateTimePicker::make('paid_at')
                    ->label('Fecha')
                    ->seconds(false)
                    ->maxDate(now()->endOfDay())
                    ->required(),
                TextInput::make('notes')
                    ->label('Nota')
                    ->placeholder('Por ejemplo: cuota 2 de ortodoncia')
                    ->maxLength(255),
            ])
            ->action(function (array $data) use ($patient) {
                $patient = $patient();
                app(BillingService::class)->registerPayment($patient, $data, self::user());

                Notification::make()
                    ->success()
                    ->title('Pago registrado')
                    ->body('Saldo del paciente: '.Money::balance($patient->balance()))
                    ->send();
            });
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
