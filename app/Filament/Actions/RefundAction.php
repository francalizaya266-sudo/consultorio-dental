<?php

namespace App\Filament\Actions;

use App\Enums\PaymentMethod;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Support\Icons\Heroicon;

/**
 * Devolver dinero que el paciente tiene a favor (un tratamiento que se canceló, un pago de más). Queda como
 * movimiento propio, con su motivo: sale de la caja del día en vez de anular un pago de otro día.
 */
final class RefundAction
{
    /** @param  Closure(): Patient  $patient */
    public static function make(Closure $patient): Action
    {
        return Action::make('refund')
            ->label('Devolver dinero')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn () => self::user()->can('create', Payment::class) && $patient()->balance() < 0)
            ->modalHeading(fn () => 'Devolver dinero a '.$patient()->full_name)
            ->modalDescription(fn () => 'Tiene a favor '.Money::format(-$patient()->balance()).'. Lo que devuelvas sale de la caja de hoy y queda registrado como devolución.')
            ->modalSubmitActionLabel('Registrar devolución')
            ->modalWidth('lg')
            ->fillForm(fn () => [
                'amount' => -$patient()->balance(),
                'method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateTimeString(),
            ])
            ->schema([
                TextInput::make('amount')
                    ->label('Devuelve')
                    ->prefix('S/')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(fn () => -$patient()->balance())
                    ->required(),
                ToggleButtons::make('method')
                    ->label('Cómo se devuelve')
                    ->options(PaymentMethod::class)
                    ->inline()
                    ->required(),
                TextInput::make('reason')
                    ->label('Motivo')
                    ->placeholder('Por ejemplo: se canceló el tratamiento')
                    ->required()
                    ->maxLength(255),
                Hidden::make('paid_at')
                    ->dehydrated(false)
                    ->rule(fn () => RegisterPaymentAction::openDay()),
            ])
            ->action(function (array $data) use ($patient) {
                $refund = app(BillingService::class)->registerRefund($patient(), (float) $data['amount'], $data['method'], $data['reason'], self::user());

                RegisterPaymentAction::notifyRegistered($refund);
            });
    }

    private static function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
