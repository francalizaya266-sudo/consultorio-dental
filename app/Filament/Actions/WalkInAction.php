<?php

namespace App\Filament\Actions;

use App\Enums\AppointmentStatus;
use App\Enums\PatientRelationship;
use App\Filament\Resources\Appointments\AppointmentResource;
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
use Filament\Forms\Components\Toggle;
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
 * Si resulta que sí tenía cita hoy, se dice ahí mismo y lo normal es marcar que llegó a esa, en vez de abrirle otra.
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
                AppointmentForm::patientSection(forToday: true),
                Section::make(fn (Get $get) => self::todaysAppointmentHeading($get))
                    ->visible(fn (Get $get) => self::todaysAppointment($get) !== null)
                    ->schema([
                        Text::make(fn (Get $get) => self::describe(self::todaysAppointment($get)))
                            ->weight(FontWeight::SemiBold),
                        Toggle::make('use_appointment')
                            ->label('Marcar que llegó a esa cita')
                            ->helperText('Así esa cita no queda como falta ni se le abre otra. Desmárcalo solo si viene por otra atención aparte.')
                            ->visible(fn (Get $get) => self::todaysAppointment($get)?->status->isChangeable() ?? false)
                            ->live(),
                        Text::make('Sigue solo si viene por otra atención aparte: se le abrirá una cita más.')
                            ->visible(fn (Get $get) => ! (self::todaysAppointment($get)?->status->isChangeable() ?? true)),
                    ]),
                Section::make('Atención')
                    ->columns(2)
                    ->hidden(fn (Get $get) => self::arrivesToAppointment($get))
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
                'use_appointment' => true,
                'doctor_id' => SlotPicker::defaultDoctorId(),
            ])
            ->action(function (array $data, Action $action) {
                $appointment = ($data['use_appointment'] ?? false) ? self::todaysAppointmentOf($data['patient_id'] ?? null) : null;

                if ($appointment?->status->isChangeable()) {
                    Notify::run(
                        fn () => app(AppointmentService::class)->markArrived($appointment, auth()->user()),
                        'Paciente en sala',
                        $action,
                        fn (Notification $notification) => ArrivalChecklist::addTo($notification, $appointment, auth()->user(), self::arrivalHeadline($appointment)),
                    );

                    return;
                }

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

    /** "Rosa Quispe llegó a su cita de las 11:30 con Dra. Elena Ríos." */
    private static function arrivalHeadline(Appointment $appointment): string
    {
        return $appointment->patient->full_name.' llegó a su cita de las '.$appointment->starts_at->format('H:i').' con '.$appointment->doctor->name.'.';
    }

    /** "12:00 · Extracción simple con Dra. Elena Ríos" */
    private static function describe(?Appointment $appointment): string
    {
        return $appointment
            ? $appointment->starts_at->format('H:i').' · '.$appointment->treatment->name.' con '.$appointment->doctor->name
            : '';
    }

    /** Según en qué va su cita de hoy: todavía no llega, ya espera en sala o ya lo están atendiendo. */
    private static function todaysAppointmentHeading(Get $get): string
    {
        return match (self::todaysAppointment($get)?->status) {
            AppointmentStatus::CheckedIn => 'Ya está en sala',
            AppointmentStatus::InProgress => 'Ya lo están atendiendo',
            default => 'Ya tiene cita hoy',
        };
    }

    /** ¿Se va a marcar que llegó a la cita que ya tenía? Entonces no se elige tratamiento ni doctor. */
    private static function arrivesToAppointment(Get $get): bool
    {
        return (bool) $get('use_appointment') && (self::todaysAppointment($get)?->status->isChangeable() ?? false);
    }

    /** La cita de hoy del paciente elegido que sigue sin cerrarse, si la hay. Un paciente nuevo no tiene. */
    private static function todaysAppointment(Get $get): ?Appointment
    {
        return $get('new_patient') ? null : self::todaysAppointmentOf($get('patient_id'));
    }

    /**
     * La primera cita de hoy del paciente que todavía espera un cierre, entre las que quien mira puede manejar
     * (el doctor, solo las suyas). Se pide varias veces al pintar la ventana: se busca una sola vez por pedido.
     */
    private static function todaysAppointmentOf(mixed $patientId): ?Appointment
    {
        if (blank($patientId)) {
            return null;
        }

        $memo = 'walk-in.todays-appointment.'.$patientId;

        if (! request()->attributes->has($memo)) {
            request()->attributes->set($memo, AppointmentResource::getEloquentQuery()
                ->where('patient_id', $patientId)
                ->whereDate('starts_at', today())
                ->whereIn('status', AppointmentStatus::open())
                ->orderBy('starts_at')
                ->first());
        }

        return request()->attributes->get($memo);
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
