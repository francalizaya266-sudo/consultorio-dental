<?php

namespace App\Filament\Resources\WhatsappMessages\Tables;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Actions\SendWhatsAppAction;
use App\Models\WhatsappMessage;
use App\Support\Phone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WhatsappMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Preparado')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('template_key')
                    ->label('Aviso')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('phone')
                    ->label('Para')
                    ->formatStateUsing(fn (WhatsappMessage $record) => $record->patient?->full_name ?? Phone::display($record->phone))
                    ->description(fn (WhatsappMessage $record) => $record->patient ? Phone::display($record->phone) : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $w) => $w
                        ->whereHas('patient', fn (Builder $p) => $p->search($search))
                        ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%'))),
                TextColumn::make('appointment.starts_at')
                    ->label('Cita')
                    ->dateTime('D d/m H:i')
                    ->description(fn (WhatsappMessage $record) => $record->appointment?->doctor->name)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->description(fn (WhatsappMessage $record) => match (true) {
                        $record->status === MessageStatus::Sent => trim(($record->sentBy?->name ?? 'Automático').' · '.$record->sent_at?->format('d/m H:i')),
                        $record->status === MessageStatus::Pending && $record->due_at?->isFuture() => 'Sale el '.$record->due_at->translatedFormat('d/m \a \l\a\s H:i'),
                        $record->status === MessageStatus::Failed => $record->error,
                        default => null,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('template_key')
                    ->label('Aviso')
                    ->options(MessageTemplateKey::class),
            ])
            ->recordActions([
                SendWhatsAppAction::make()
                    ->label(fn (WhatsappMessage $record) => $record->status === MessageStatus::Sent ? 'Reenviar' : 'Enviar por WhatsApp')
                    ->button()
                    ->visible(fn (WhatsappMessage $record) => $record->status !== MessageStatus::Cancelled)
                    ->authorize('update'),
                SendWhatsAppAction::discard()
                    ->iconButton()
                    ->tooltip('Descartar')
                    ->authorize('update'),
            ])
            ->poll('30s')
            ->emptyStateHeading('No hay avisos por enviar')
            ->emptyStateDescription('Cuando se reserve, mueva o cancele una cita, el aviso aparecerá aquí.')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right');
    }
}
