<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Actions\AppointmentActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Citas del paciente, de la más reciente a la más antigua. */
class AppointmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    protected static ?string $title = 'Citas';

    protected static ?string $modelLabel = 'cita';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $upcoming = $ownerRecord->appointments()->upcoming()->count();

        return $upcoming > 0 ? $upcoming.' próx.' : null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['doctor', 'treatment', 'patient.account'])
                ->when(! $user->managesAllAgendas(), fn (Builder $q) => $q->where('doctor_id', $user->doctorId())))
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Fecha y hora')
                    ->dateTime('D d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('treatment.name')
                    ->label('Tratamiento'),
                TextColumn::make('doctor.name')
                    ->label('Doctor'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->recordUrl(fn (Appointment $record) => AppointmentResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('book')
                    ->label('Nueva cita')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => AppointmentResource::getUrl('create', ['patient' => $this->getOwnerRecord()->getKey()])),
            ])
            ->recordActions(AppointmentActions::all())
            ->emptyStateHeading('Sin citas todavía');
    }
}
