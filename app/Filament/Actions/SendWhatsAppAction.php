<?php

namespace App\Filament\Actions;

use App\Enums\MessageStatus;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * Abre el WhatsApp del consultorio con el aviso ya escrito (con un enlace
 * personal nuevo). Se puede retocar el texto antes de abrirlo.
 */
final class SendWhatsAppAction
{
    /**
     * @param  Closure(mixed): ?WhatsappMessage  $resolveMessage  Recibe el registro de la fila y devuelve el aviso.
     */
    public static function make(string $name = 'sendWhatsApp', ?Closure $resolveMessage = null): Action
    {
        $resolveMessage ??= fn (WhatsappMessage $record) => $record;

        return Action::make($name)
            ->label('Enviar por WhatsApp')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('success')
            ->modalHeading('Enviar por WhatsApp')
            ->modalDescription('Revisa el mensaje. Se abrirá WhatsApp con el texto listo: solo presiona Enviar.')
            ->modalSubmitActionLabel('Abrir WhatsApp')
            ->fillForm(fn ($record) => [
                'body' => app(WhatsAppOutbox::class)->render($resolveMessage($record), auth()->user()),
            ])
            ->schema([
                Textarea::make('body')
                    ->label('Mensaje')
                    ->rows(9)
                    ->required(),
            ])
            ->action(function (array $data, $record, Component $livewire) use ($resolveMessage) {
                $url = app(WhatsAppOutbox::class)->clickToChatUrl($resolveMessage($record), auth()->user(), $data['body']);

                $livewire->js('window.open('.json_encode($url).', "_blank")');
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
        return Action::make($name)
            ->label('Enviar por WhatsApp')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('success')
            ->modalHeading('Enviar por WhatsApp')
            ->modalDescription('Revisa el mensaje. Se abrirá WhatsApp con el texto listo: solo presiona Enviar.')
            ->modalSubmitActionLabel('Abrir WhatsApp')
            ->fillForm(function (Action $action) use ($draft) {
                $message = $action->evaluate($draft);

                if (! $message) {
                    $action->cancel();
                }

                return ['body' => app(WhatsAppOutbox::class)->render($message, auth()->user())];
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
                $url = app(WhatsAppOutbox::class)->clickToChatUrl($message, auth()->user(), $data['body']);

                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
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
            ->visible(fn (WhatsappMessage $record) => $record->status === MessageStatus::Pending)
            ->action(fn (WhatsappMessage $record) => $record->update(['status' => MessageStatus::Cancelled]));
    }
}
