<?php

namespace App\Filament\Actions;

use App\Enums\PatientRelationship;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\Support\ArrivalChecklist;
use App\Filament\Support\Notify;
use App\Filament\Support\SlotPicker;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Services\Agenda\Slot;
use App\Services\Booking\AppointmentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "Llegó sin cita": el paciente está en el mostrador y no tiene cita. En una sola ventana se elige (o se
 * registra) al paciente y qué viene a hacerse; el sistema le da el primer hueco de hoy y lo deja en sala.
 */
final class WalkInAction
{
    public static function make(): Action
    {
        return Action::make('walkIn')
            ->label('Llegó sin cita')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->modalHeading('Llegó sin cita')
            ->modalDescription('Se le da el primer hueco de hoy y queda en sala de una vez.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Pasar a sala')
            ->schema([
                AppointmentForm::patientSection(followUsualDay: false),
                Section::make('Atención')
                    ->columns(2)
                    ->schema([
                        // Al elegir qué se hace, queda puesto quien se desocupa primero.
                        SlotPicker::treatmentSelect()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $set('doctor_id', self::soonestDoctorId($get) ?? $get('doctor_id'))),
                        SlotPicker::doctorSelect(),
                        Text::make(fn (Get $get) => self::roomToday($get))
                            ->weight(FontWeight::SemiBold)
                            ->color(fn (Get $get) => self::slot($get) ? 'success' : 'danger')
                            ->visible(fn (Get $get) => filled($get('treatment_id')) && filled($get('doctor_id')))
                            ->columnSpanFull(),
                    ]),
            ])
            ->fillForm(fn () => [
                'new_patient' => false,
                'patient' => ['relationship' => PatientRelationship::Self->value],
            ])
            ->action(function (array $data, Action $action) {
                $appointment = null;

                Notify::run(
                    function () use ($data, &$appointment) {
                        $appointment = CreateAppointmentAction::book($data, walkIn: true);
                    },
                    'Paciente en sala',
                    $action,
                    function (Notification $notification) use (&$appointment) {
                        return ArrivalChecklist::addTo($notification, $appointment, auth()->user(), self::headline($appointment));
                    },
                );
            });
    }

    /** "Rosa Quispe pasa con Dra. Elena Ríos a las 11:30." */
    private static function headline(Appointment $appointment): string
    {
        return $appointment->patient->full_name.' pasa con '.$appointment->doctor->name.' a las '.$appointment->starts_at->format('H:i').'.';
    }

    /** Lo que se le dice al paciente antes de pasarlo a sala. */
    private static function roomToday(Get $get): string
    {
        $slot = self::slot($get);

        return $slot
            ? 'Lo puede atender hoy a las '.$slot->label().'.'
            : 'Ya no tiene un hueco hoy para ese tratamiento. Elige otro doctor, o agéndalo con "Nueva cita" para otro día.';
    }

    private static function slot(Get $get): ?Slot
    {
        $doctor = Doctor::find($get('doctor_id'));
        $treatment = Treatment::find($get('treatment_id'));

        return $doctor && $treatment
            ? app(AppointmentService::class)->firstRoomToday($doctor, $treatment, filled($get('patient_id')) ? (int) $get('patient_id') : null)
            : null;
    }

    /** De quienes hacen ese tratamiento, quien tiene el hueco más cercano hoy. Null si el doctor ya está elegido o nadie tiene. */
    private static function soonestDoctorId(Get $get): ?int
    {
        $treatment = Treatment::find($get('treatment_id'));

        if (! $treatment || filled($get('doctor_id'))) {
            return null;
        }

        return Doctor::query()
            ->active()
            ->whereHas('treatments', fn ($query) => $query->whereKey($treatment->getKey()))
            ->get()
            ->map(fn (Doctor $doctor) => ['id' => $doctor->getKey(), 'slot' => app(AppointmentService::class)->firstRoomToday($doctor, $treatment)])
            ->filter(fn (array $option) => $option['slot'] !== null)
            ->sortBy(fn (array $option) => $option['slot']->startsAt->getTimestamp())
            ->first()['id'] ?? null;
    }
}
