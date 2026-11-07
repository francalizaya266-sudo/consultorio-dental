<?php

namespace App\Filament\Resources\Accounts;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Actions\SendWhatsAppAction;
use App\Models\Account;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Bloquear o permitir las reservas web de un celular y enviarle su enlace de Mi cuenta. */
final class AccountActions
{
    /**
     * Enlace personal para entrar a Mi cuenta desde un celular nuevo ("Pedir acceso"). Desde la ficha del
     * paciente, $account da el celular de su familia.
     *
     * @param  Closure(mixed): ?Account|null  $account
     */
    public static function sendAccessLink(?Closure $account = null): Action
    {
        $account ??= fn (Account $record) => $record;

        return SendWhatsAppAction::compose('sendAccessLink', function (Action $action) use ($account) {
            $phone = $action->evaluate($account);

            return $phone ? app(WhatsAppOutbox::class)->draftForAccount($phone, MessageTemplateKey::AccessLink, patientId: $phone->holder?->getKey()) : null;
        })
            ->label('Enviar enlace de Mi cuenta')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->visible(fn (Action $action) => ($phone = $action->evaluate($account)) !== null && ! $phone->isBlocked())
            // Si el paciente lo había pedido desde la web, ese aviso ya quedó atendido.
            ->after(fn (Action $action) => WhatsappMessage::query()
                ->whereBelongsTo($action->evaluate($account))
                ->where('template_key', MessageTemplateKey::AccessLink)
                ->pending()
                ->update(['status' => MessageStatus::Cancelled]));
    }

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
            ->modalSubmitActionLabel('Desbloquear')
            ->action(function (Account $record) {
                $record->update(['blocked_at' => null, 'blocked_reason' => null, 'blocked_by_user_id' => null]);

                Notification::make()->success()->title('Celular desbloqueado')->send();
            });
    }
}
