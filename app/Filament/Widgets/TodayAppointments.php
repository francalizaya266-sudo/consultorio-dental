<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\User;
use App\Support\Dates;
use App\Support\Phone;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * La agenda de hoy como línea de tiempo: la hora grande a la izquierda, el color de su estado en el
 * borde y una línea de "ahora" antes de la próxima cita. El primer botón de cada cita es el paso que
 * sigue (confirmar, llegó, atender, cobrar) y se ve como botón; los demás quedan más discretos.
 * Quien está en sala muestra cuánto lleva esperando.
 */
class TodayAppointments extends TableWidget
{
    /** Desde aquí la espera se marca en rojo: alguien tiene que ver qué pasa. */
    public const LONG_WAIT_MINUTES = 20;

    /** La agenda de hoy se ve apenas abre "Hoy", sin un recuadro vacío mientras carga. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** La próxima cita que todavía no empieza: antes de ella va la línea de "ahora". */
    private int|false|null $nextId = null;

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();

        $showDoctor = AppointmentsTable::showsDoctor($user);
        $seesMoney = $user->can('viewAny', Charge::class);

        return $table
            ->heading('Agenda de hoy')
            ->query(fn () => AppointmentResource::getEloquentQuery()
                ->with(['patient' => fn ($patient) => $patient->with('account')->when($seesMoney, fn ($q) => $q->withBalance()), 'treatment', 'doctor'])
                ->whereDate('starts_at', today())
                ->where('status', '!=', AppointmentStatus::Cancelled))
            ->columns([
                Split::make([
                    TextColumn::make('starts_at')
                        ->formatStateUsing(fn (Appointment $record) => $record->starts_at->format('H:i'))
                        ->description(fn (Appointment $record) => Dates::duration($record->starts_at->diffInMinutes($record->ends_at)))
                        ->weight(FontWeight::Bold)
                        ->extraAttributes(['class' => 'dash-appt-time'])
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('patient.full_name')
                            ->weight(FontWeight::SemiBold)
                            ->wrap(),
                        TextColumn::make('treatment.name')
                            ->color('gray')
                            ->size(TextSize::Small)
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
                        TextColumn::make('waiting')
                            ->state(fn (Appointment $record) => ($minutes = $record->waitingMinutes()) !== null ? 'En sala hace '.Dates::duration($minutes) : null)
                            ->icon('heroicon-m-clock')
                            ->color(fn (Appointment $record) => $record->waitingMinutes() >= self::LONG_WAIT_MINUTES ? 'danger' : 'warning')
                            ->size(TextSize::Small),
                        AppointmentsTable::flagsColumn(withBalance: $seesMoney),
                    ])->space(1)->alignEnd()->grow(false),
                ]),
            ])
            ->defaultSort('starts_at')
            ->paginated(false)
            ->recordClasses(fn (Appointment $record) => [
                'dash-appt',
                'dash-appt-'.$record->status->value,
                'dash-appt-next' => $record->getKey() === $this->nextId(),
            ])
            ->recordUrl(fn (Appointment $record) => AppointmentResource::getUrl('view', ['record' => $record]))
            ->recordActions(AppointmentActions::all())
            ->poll('30s')
            ->emptyStateHeading('No hay citas hoy')
            ->emptyStateDescription('Las citas de hoy aparecen aquí en orden, con el botón del paso que sigue.')
            ->emptyStateIcon('heroicon-o-calendar');
    }

    private function nextId(): ?int
    {
        if ($this->nextId === null) {
            $this->nextId = AppointmentResource::getEloquentQuery()
                ->whereDate('starts_at', today())
                ->where('starts_at', '>=', now())
                ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
                ->orderBy('starts_at')
                ->toBase()
                ->value('id') ?? false;
        }

        return $this->nextId ?: null;
    }
}
