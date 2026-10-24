<?php

namespace App\Filament\Resources\RescheduleRequests;

use App\Enums\RescheduleRequestStatus;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\RescheduleRequests\Pages\ListRescheduleRequests;
use App\Filament\Resources\RescheduleRequests\Tables\RescheduleRequestsTable;
use App\Models\RescheduleRequest;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Pacientes que proponen otros horarios: se aprueba uno con un clic. */
class RescheduleRequestResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'solicitudes-de-cambio';

    protected static ?string $model = RescheduleRequest::class;

    protected static ?string $modelLabel = 'solicitud de cambio';

    protected static ?string $pluralModelLabel = 'solicitudes de cambio';

    protected static ?string $navigationLabel = 'Solicitudes de cambio';

    protected static ?string $navigationParentItem = 'Agenda';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function table(Table $table): Table
    {
        return RescheduleRequestsTable::configure($table);
    }

    /** Recepción y administración ven todas; el doctor, las de sus citas. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['appointment.patient.account', 'appointment.doctor', 'appointment.treatment', 'options.doctor', 'requestedTreatment', 'resolvedBy'])
            ->when(! $user->managesAllAgendas(), fn (Builder $q) => $q->whereHas('appointment', fn (Builder $a) => $a->where('doctor_id', $user->doctorId())));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', RescheduleRequestStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRescheduleRequests::route('/'),
        ];
    }
}
