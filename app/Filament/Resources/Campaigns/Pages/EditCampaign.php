<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Campaign;
use App\Services\WhatsApp\CampaignBroadcast;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;

/** @property Campaign $record */
class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        $isPublished = fn () => Campaign::query()->published()->whereKey($this->record->getKey())->exists();
        $url = fn () => route('site.promotion', $this->record->slug);

        return [
            self::announceAction()->visible($isPublished),
            Action::make('copyLink')
                ->label('Copiar enlace')
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->visible($isPublished)
                // Para pegarlo en el estado de WhatsApp, Facebook o Instagram. Si el navegador no deja copiar, lo muestra para copiarlo a mano.
                ->alpineClickHandler(fn () => self::copyJs($url())),
            Action::make('viewOnSite')
                ->label('Ver en la web')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible($isPublished)
                ->url($url, shouldOpenInNewTab: true),
            // En el menú ⋮, no como botón rojo: el rojo queda para lo urgente.
            ActionGroup::make([
                DeleteAction::make(),
                RestoreAction::make(),
            ]),
        ];
    }

    private static function copyJs(string $text): string
    {
        $text = Js::from($text);
        $copied = Js::from('Enlace copiado');
        $manual = Js::from('Copia el enlace');

        return "(window.navigator.clipboard ? window.navigator.clipboard.writeText({$text}) : Promise.reject())"
            ."  .then(() => \$tooltip({$copied}, { theme: \$store.theme, timeout: 2000 }), () => window.prompt({$manual}, {$text}))";
    }

    /**
     * Prepara un aviso por cada celular que aceptó recibir promociones (sin los bloqueados ni a quien ya se le
     * avisó de esta). Quedan en Avisos → Promociones y se envían uno por uno, como los demás.
     */
    private static function announceAction(): Action
    {
        $audience = fn (Campaign $record) => app(CampaignBroadcast::class)->audience($record)->count();

        return Action::make('announce')
            ->label('Avisar por WhatsApp')
            ->icon(Heroicon::OutlinedMegaphone)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedMegaphone)
            ->modalHeading('Avisar esta promoción por WhatsApp')
            ->modalDescription(fn (Campaign $record) => ($count = $audience($record)) === 0
                ? 'No queda nadie por avisar: ya se preparó para todos los que aceptaron recibir promociones.'
                : ($count === 1 ? 'Se prepara 1 aviso' : "Se preparan {$count} avisos").', uno por cada celular que aceptó recibir promociones. Quedan en Avisos → Promociones, aparte de lo de las citas, y se envían uno por uno.')
            ->modalSubmitActionLabel(fn (Campaign $record) => ($count = $audience($record)) === 1 ? 'Preparar 1 aviso' : "Preparar {$count} avisos")
            ->modalSubmitAction(fn (Action $action, Campaign $record) => $action->disabled($audience($record) === 0))
            ->action(function (Campaign $record) {
                $queued = app(CampaignBroadcast::class)->queue($record);

                Notification::make()
                    ->success()
                    ->title($queued === 1 ? '1 aviso listo para enviar' : "{$queued} avisos listos para enviar")
                    ->body('Están en Avisos → Promociones.')
                    ->actions([
                        Action::make('open')
                            ->label('Ir a Promociones')
                            ->url(WhatsappMessageResource::getUrl('index', ['tab' => 'promotions'])),
                    ])
                    ->send();
            });
    }
}
