<?php

namespace App\Filament\Resources\Accounts;

use App\Models\Account;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Bloquear o permitir las reservas web de un celular. */
final class AccountActions
{
    public static function block(): Action
    {
        return Action::make('block')
            ->label('Bloquear')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Account $record) => ! $record->isBlocked())
            ->authorize('block')
            ->modalHeading('Bloquear reservas web')
            ->modalDescription('Este celular no podrá reservar desde la web. El consultorio sí puede seguir agendándole citas.')
            ->modalSubmitActionLabel('Bloquear')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(2)
                    ->maxLength(250),
            ])
            ->action(function (array $data, Account $record) {
                $record->update([
                    'blocked_at' => now(),
                    'blocked_reason' => $data['reason'],
                    'blocked_by_user_id' => auth()->id(),
                ]);

                Notification::make()->success()->title('Celular bloqueado para reservas web')->send();
            });
    }

    public static function unblock(): Action
    {
        return Action::make('unblock')
            ->label('Desbloquear')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Account $record) => $record->isBlocked())
            ->authorize('block')
            ->requiresConfirmation()
            ->modalHeading('¿Permitir otra vez las reservas web?')
            ->action(function (Account $record) {
                $record->update(['blocked_at' => null, 'blocked_reason' => null, 'blocked_by_user_id' => null]);

                Notification::make()->success()->title('Celular desbloqueado')->send();
            });
    }
}
