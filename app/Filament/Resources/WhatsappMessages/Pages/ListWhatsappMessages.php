<?php

namespace App\Filament\Resources\WhatsappMessages\Pages;

use App\Enums\MessageStatus;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWhatsappMessages extends ListRecords
{
    protected static string $resource = WhatsappMessageResource::class;

    protected static ?string $title = 'Avisos';

    public function getSubheading(): ?string
    {
        return 'Presiona "Enviar por WhatsApp": se abre el chat del paciente con el mensaje listo y solo tienes que tocar Enviar.';
    }

    public function getTabs(): array
    {
        return array_filter([
            'due' => Tab::make('Por enviar')
                ->badge(fn () => WhatsappMessageResource::getEloquentQuery()->due()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->due()),
            // Avisos con fecha de salida. Aparece cuando hay alguno.
            'scheduled' => WhatsappMessageResource::getEloquentQuery()->pending()->where('due_at', '>', now())->exists()
                ? Tab::make('Programados')
                    ->modifyQueryUsing(fn (Builder $query) => $query->pending()->where('due_at', '>', now()))
                : null,
            // Las promociones, aparte de lo de las citas. Aparece cuando hay alguna por enviar.
            'promotions' => WhatsappMessageResource::getEloquentQuery()->promotions()->exists()
                ? Tab::make('Promociones')
                    ->badge(fn () => WhatsappMessageResource::getEloquentQuery()->promotions()->count() ?: null)
                    ->modifyQueryUsing(fn (Builder $query) => $query->promotions())
                : null,
            'sent' => Tab::make('Enviados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', MessageStatus::Sent)),
            // Solo con la API de WhatsApp: los que no salieron. Aparece cuando hay alguno.
            'failed' => WhatsappMessageResource::getEloquentQuery()->where('status', MessageStatus::Failed)->exists()
                ? Tab::make('No enviados')
                    ->badge(fn () => WhatsappMessageResource::getEloquentQuery()->failedRecently()->count() ?: null)
                    ->badgeColor('danger')
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('status', MessageStatus::Failed))
                : null,
            'all' => Tab::make('Todos'),
        ]);
    }
}
