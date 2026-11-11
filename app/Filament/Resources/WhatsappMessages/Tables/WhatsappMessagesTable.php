<?php

namespace App\Filament\Resources\WhatsappMessages\Tables;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Phone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class WhatsappMessagesTable
{
    /** Pestañas de lo que falta enviar: van por la cita más próxima, no por el último preparado. */
    private const TO_SEND = ['due', 'scheduled', 'promotions'];

    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                // Cuándo se preparó va debajo del nombre del aviso: así el botón de enviar entra sin correr la tabla.
                TextColumn::make('template_key')
                    ->label('Aviso')
                    ->badge()
                    ->color('gray')
                    ->description(fn (WhatsappMessage $record) => 'Preparado '.$record->created_at->diffForHumans()),
                // El mensaje a la vista, para saber qué se manda sin abrirlo.
                TextColumn::make('preview')
                    ->label('Mensaje')
                    ->state(fn (WhatsappMessage $record) => Str::limit(Str::squish(str_replace('{enlace}', '(su enlace)', app(WhatsAppOutbox::class)->draft($record))), 140))
                    ->color('gray')
                    ->size('sm')
                    ->wrap()
                    ->lineClamp(3)
                    // Sin un ancho mínimo, la tabla la aprieta hasta dejar una palabra por línea.
                    ->extraAttributes(['style' => 'min-width: 13rem']),
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
                    ->description(fn (WhatsappMessage $record) => AppointmentsTable::showsDoctor(auth()->user()) ? $record->appointment?->doctor->name : null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    // En "Por enviar" y "Promociones" todos dicen lo mismo, y la columna sacaba el botón de la pantalla.
                    ->hidden(fn ($livewire) => in_array($livewire->activeTab ?? 'due', ['due', 'promotions'], true))
                    ->description(fn (WhatsappMessage $record) => match (true) {
                        $record->status === MessageStatus::Sent => trim(($record->sentBy?->name ?? 'Automático').' · '.$record->sent_at?->format('d/m H:i')),
                        $record->status === MessageStatus::Pending && $record->due_at?->isFuture() => 'Sale el '.$record->due_at->translatedFormat('d/m \a \l\a\s H:i'),
                        $record->status === MessageStatus::Failed => $record->error,
                        default => null,
                    }),
            ])
            // Por enviar: primero lo de la cita más próxima (lo que no es de una cita, por cuándo se preparó).
            // En Enviados y Todos, el último arriba.
            ->defaultSort(fn (Builder $query, $livewire) => in_array($livewire->activeTab ?? null, self::TO_SEND, true)
                ? $query->orderByRaw('coalesce((select a.starts_at from appointments a where a.id = whatsapp_messages.appointment_id), whatsapp_messages.created_at)')->orderBy('id')
                : $query->orderByDesc('created_at')->orderByDesc('id'))
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
                SendWhatsAppAction::notSent()
                    ->authorize('update'),
                SendWhatsAppAction::discard()
                    ->iconButton()
                    ->tooltip('Descartar')
                    ->authorize('update'),
            ])
            ->poll('30s')
            ->emptyStateHeading(fn ($livewire) => match ($livewire->activeTab ?? null) {
                'sent' => 'Todavía no se envió ningún aviso',
                'scheduled' => 'No hay avisos programados',
                'all' => 'Todavía no hay avisos',
                default => 'No hay avisos por enviar',
            })
            ->emptyStateDescription('Cuando se reserve, mueva o cancele una cita, el aviso aparecerá aquí.')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right');
    }
}
