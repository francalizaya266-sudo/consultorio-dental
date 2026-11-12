<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesWithToday;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use App\Models\User;
use App\Support\Dates;
use Filament\Widgets\Widget;

/**
 * Quién está en sala y hace cuánto, de quien más espera a quien acaba de llegar. Desde
 * TodayAppointments::LONG_WAIT_MINUTES la espera va en rojo. Es de recepción: el doctor (aunque
 * también administre) ve a quien lo espera en "Tu próximo paciente" y no la ve otra vez aquí.
 * Quien quedó "en sala" horas después de su cita ya no sale: es una cita sin cerrar. Se actualiza sola cada 30 s.
 */
class WaitingRoom extends Widget
{
    use RefreshesWithToday;

    /** La sala de espera se ve apenas abre "Hoy", sin un recuadro vacío mientras carga. */
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.waiting-room';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->managesAllAgendas() && ! NextPatient::canView();
    }

    /** @return array{patients: list<array{name: string, detail: string, waited: string, long: bool, url: string}>} */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $severalDoctors = AppointmentsTable::showsDoctor($user);

        $waiting = AppointmentResource::getEloquentQuery()
            ->with(['patient', 'treatment', 'doctor'])
            ->waitingNow()
            ->orderBy('checked_in_at')
            ->get();

        return ['patients' => $waiting->map(fn (Appointment $appointment) => [
            'name' => $appointment->patient->full_name,
            'detail' => collect([
                $appointment->treatment->name,
                'cita de las '.$appointment->starts_at->format('H:i'),
                $severalDoctors ? $appointment->doctor?->name : null,
            ])->filter()->implode(' · '),
            'waited' => Dates::duration((int) $appointment->waitingMinutes()),
            'long' => $appointment->waitingMinutes() >= TodayAppointments::LONG_WAIT_MINUTES,
            'url' => AppointmentResource::getUrl('view', ['record' => $appointment]),
        ])->all()];
    }
}
