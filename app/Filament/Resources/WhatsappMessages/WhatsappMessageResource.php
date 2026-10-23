<?php

namespace App\Filament\Resources\WhatsappMessages;

use App\Filament\Resources\WhatsappMessages\Pages\ListWhatsappMessages;
use App\Filament\Resources\WhatsappMessages\Tables\WhatsappMessagesTable;
use App\Models\User;
use App\Models\WhatsappMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Bandeja de avisos: el sistema los prepara y recepción los envía con un clic. */
class WhatsappMessageResource extends Resource
{
    protected static ?string $model = WhatsappMessage::class;

    protected static ?string $modelLabel = 'aviso';

    protected static ?string $pluralModelLabel = 'avisos';

    protected static ?string $navigationLabel = 'Avisos por enviar';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function table(Table $table): Table
    {
        return WhatsappMessagesTable::configure($table);
    }

    /** Recepción y administración ven todos; el doctor, los de sus citas. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['patient', 'account', 'appointment.doctor', 'appointment.treatment', 'sentBy'])
            ->when(! $user->managesAllAgendas(), fn (Builder $q) => $q->whereHas('appointment', fn (Builder $a) => $a->where('doctor_id', $user->doctorId())));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->due()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Avisos listos para enviar';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsappMessages::route('/'),
        ];
    }
}
