<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Citas de hoy en orden, con los botones del día: confirmar, llegó, atender, atendido. */
class TodayAppointments extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();

        return $table
            ->heading('Citas de hoy')
            ->query(fn () => AppointmentResource::getEloquentQuery()
                ->whereDate('starts_at', today())
                ->where('status', '!=', AppointmentStatus::Cancelled))
            ->columns(AppointmentsTable::columns(showDoctor: AppointmentsTable::showsDoctor($user), timeOnly: true))
            ->defaultSort('starts_at')
            ->paginated(false)
            ->recordUrl(fn (Appointment $record) => AppointmentResource::getUrl('view', ['record' => $record]))
            ->recordActions(AppointmentActions::all())
            ->stackedOnMobile()
            ->headerActions([
                Action::make('new')
                    ->label('Nueva cita')
                    ->icon('heroicon-o-plus')
                    ->url(AppointmentResource::getUrl('create')),
            ])
            ->poll('30s')
            ->emptyStateHeading('No hay citas hoy')
            ->emptyStateIcon('heroicon-o-calendar');
    }
}
