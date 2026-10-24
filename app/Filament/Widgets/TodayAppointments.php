<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Actions\CreateAppointmentAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\User;
use App\Support\Phone;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Citas de hoy en orden, una o dos líneas por cita ("09:00 · María Quispe · Limpieza" y su estado),
 * con los botones del día: confirmar, llegó, atender, atendido, cobrar.
 */
class TodayAppointments extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();

        $showDoctor = AppointmentsTable::showsDoctor($user);
        $seesMoney = $user->can('viewAny', Charge::class);

        return $table
            ->heading('Citas de hoy')
            ->query(fn () => AppointmentResource::getEloquentQuery()
                ->with(['patient' => fn ($patient) => $patient->with('account')->when($seesMoney, fn ($q) => $q->withBalance()), 'treatment', 'doctor'])
                ->whereDate('starts_at', today())
                ->where('status', '!=', AppointmentStatus::Cancelled))
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('starts_at')
                            ->formatStateUsing(fn (Appointment $record) => $record->starts_at->format('H:i').' · '.$record->patient->full_name.' · '.$record->treatment->name)
                            ->weight(FontWeight::SemiBold)
                            ->wrap(),
                        Split::make([
                            TextColumn::make('phone')
                                ->state(fn (Appointment $record) => $record->patient->account ? Phone::display($record->patient->account->phone) : null)
                                ->icon('heroicon-m-phone')
                                ->color('gray')
                                ->size(TextSize::Small)
                                ->grow(false),
                            TextColumn::make('doctor.name')
                                ->icon('heroicon-m-user')
                                ->color('gray')
                                ->size(TextSize::Small)
                                ->visible($showDoctor)
                                ->grow(false),
                        ]),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('status')->badge(),
                        AppointmentsTable::flagsColumn(withBalance: $seesMoney),
                    ])->space(1)->alignEnd()->grow(false),
                ]),
            ])
            ->defaultSort('starts_at')
            ->paginated(false)
            ->recordUrl(fn (Appointment $record) => AppointmentResource::getUrl('view', ['record' => $record]))
            ->recordActions(AppointmentActions::all())
            ->headerActions([
                CreateAppointmentAction::make('new'),
            ])
            ->poll('30s')
            ->emptyStateHeading('No hay citas hoy')
            ->emptyStateIcon('heroicon-o-calendar');
    }
}
