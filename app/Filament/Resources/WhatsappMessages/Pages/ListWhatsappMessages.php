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

    public function getSubheading(): ?string
    {
        return 'Presiona "Enviar por WhatsApp": se abre el chat del paciente con el mensaje listo y solo tienes que tocar Enviar.';
    }

    public function getTabs(): array
    {
        return [
            'due' => Tab::make('Por enviar')
                ->badge(fn () => WhatsappMessageResource::getEloquentQuery()->due()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->due()),
            'scheduled' => Tab::make('Programados')
                ->modifyQueryUsing(fn (Builder $query) => $query->pending()->where('due_at', '>', now())),
            'sent' => Tab::make('Enviados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', MessageStatus::Sent)),
            'all' => Tab::make('Todos'),
        ];
    }
}
