<?php

namespace App\Filament\Actions;

use App\Enums\MessageStatus;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Phone;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * Abre el WhatsApp del consultorio con el aviso ya escrito (con un enlace personal nuevo, creado al
 * abrirlo). Se puede retocar el texto antes, y deshacer si al final no se envió.
 */
final class SendWhatsAppAction
{
    /**
     * @param  Closure(mixed): ?WhatsappMessage  $resolveMessage  Recibe el registro de la fila y devuelve el aviso.
     */
    public static function make(string $name = 'sendWhatsApp', ?Closure $resolveMessage = null): Action
    {
        $resolveMessage ??= fn (WhatsappMessage $record) => $record;

        return self::opening(Action::make($name))
            ->modalHeading(fn ($record) => self::heading($resolveMessage($record)))
            ->modalDescription(fn ($record) => self::description($resolveMessage($record)))
            ->fillForm(fn ($record) => [
                'body' => app(WhatsAppOutbox::class)->draft($resolveMessage($record)),
            ])
            ->schema([
                Textarea::make('body')
                    ->label('Mensaje')
                    ->rows(9)
                    ->required(),
            ])
            ->action(function (array $data, $record, Component $livewire) use ($resolveMessage) {
                self::open($resolveMessage($record), $data['body'], $livewire);
            });
    }

    /**
     * Mensaje que no estaba en la bandeja (receta, presupuesto): se arma al abrir, se puede
     * retocar y recién al abrir WhatsApp queda registrado como enviado.
     *
     * @param  Closure  $draft  Devuelve el aviso sin guardar, o null (después de avisar por qué) si no hay nada que enviar. Recibe $record, $livewire, etc.
     */
    public static function compose(string $name, Closure $draft): Action
    {
        return self::opening(Action::make($name))
            ->modalHeading('Enviar por WhatsApp')
            ->modalDescription(self::DESCRIPTION)
            ->fillForm(function (Action $action) use ($draft) {
                $message = $action->evaluate($draft);

                if (! $message) {
                    $action->cancel();
                }

                return ['body' => app(WhatsAppOutbox::class)->draft($message)];
            })
            ->schema([
                Textarea::make('body')
                    ->label('Mensaje')
                    ->rows(12)
                    ->required(),
            ])
            ->action(function (array $data, Action $action, Component $livewire) use ($draft) {
                /** @var WhatsappMessage|null $message */
                $message = $action->evaluate($draft);

                if (! $message) {
                    $action->halt();
                }

                $message->save();
                self::open($message, $data['body'], $livewire);
            });
    }

    /** Se abrió WhatsApp pero el mensaje no llegó a enviarse: vuelve a "Por enviar". */
    public static function notSent(): Action
    {
        return Action::make('notSent')
            ->label('No se envió')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (WhatsappMessage $record) => WhatsAppOutbox::canUndoSend($record))
            ->action(function (WhatsappMessage $record) {
                app(WhatsAppOutbox::class)->markNotSent($record);
                Notification::make()->success()->title('Volvió a "Por enviar"')->send();
            });
    }

    private const DESCRIPTION = 'Revisa el mensaje. Se abrirá WhatsApp con el texto listo: solo presiona Enviar.';

    /**
     * El botón abre una pestaña vacía en el mismo toque (el celular no bloquea lo que abre un toque) y,
     * cuando el servidor arma el enlace, la lleva a WhatsApp.
     */
    private static function opening(Action $action): Action
    {
        return $action
            ->label('Enviar por WhatsApp')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('success')
            ->modalSubmitActionLabel('Abrir WhatsApp')
            ->modalSubmitAction(fn (Action $submit) => $submit->extraAttributes([
                'x-on:click' => "window.waTab = window.open('', '_blank')",
            ]));
    }

    private static function open(WhatsappMessage $message, string $body, Component $livewire): void
    {
        $url = app(WhatsAppOutbox::class)->clickToChatUrl($message, auth()->user(), $body);
        $to = $message->patient?->full_name ?? Phone::display($message->phone);

        $livewire->js('(() => { const url = '.json_encode($url).'; const tab = window.waTab; window.waTab = null; if (tab && ! tab.closed) { tab.location.href = url; } else { window.open(url, "_blank"); } })()');

        // Si el navegador no dejó abrirlo, queda el enlace a mano; y si al final no se envió, se puede deshacer.
        Notification::make()
            ->success()
            ->title("Aviso para {$to} abierto en WhatsApp")
            ->body('Si al final no se envió, búscalo en Avisos › Enviados y toca «No se envió».')
            ->actions([
                Action::make('reopen')->label('Abrir de nuevo')->url($url, shouldOpenInNewTab: true)->link(),
            ])
            ->send();
    }

    private static function heading(WhatsappMessage $message): string
    {
        $to = $message->patient?->full_name;

        return $to ? "WhatsApp para {$to}" : 'WhatsApp a '.Phone::display($message->phone);
    }

    private static function description(WhatsappMessage $message): string
    {
        $to = $message->patient ? Phone::display($message->phone).'. ' : '';

        return $to.self::DESCRIPTION.(str_contains(app(WhatsAppOutbox::class)->draft($message), '{enlace}')
            ? ' {enlace} se cambia por su enlace personal al abrirlo.'
            : '');
    }

    /** Descartar un aviso que ya no hace falta enviar. */
    public static function discard(): Action
    {
        return Action::make('discard')
            ->label('Descartar')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('¿Descartar este aviso?')
            ->modalDescription('No se le enviará. Queda en Todos, como descartado.')
            ->modalSubmitActionLabel('Descartar aviso')
            ->modalCancelActionLabel('Volver')
            ->visible(fn (WhatsappMessage $record) => $record->status === MessageStatus::Pending)
            ->action(fn (WhatsappMessage $record) => $record->update(['status' => MessageStatus::Cancelled]));
    }
}
