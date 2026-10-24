<?php

namespace App\Filament\Actions;

use App\Enums\PatientRelationship;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\Support\Notify;
use App\Filament\Support\SlotPicker;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\Patients\PatientRegistrar;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * "Nueva cita" en una ventana sobre la agenda: llega con el doctor, el día y la hora que se tocaron,
 * y el paciente nuevo se registra en la misma ventana.
 */
final class CreateAppointmentAction
{
    public static function make(string $name = 'createAppointment'): Action
    {
        return Action::make($name)
            ->label('Nueva cita')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Nueva cita')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Agendar cita')
            ->schema(fn (Schema $schema) => AppointmentForm::configure($schema))
            ->fillForm(fn (array $arguments) => self::defaults($arguments))
            ->action(fn (array $data, Action $action) => Notify::run(
                fn () => self::book($data),
                'Cita creada. El aviso al paciente está listo en "Avisos por enviar".',
                $action,
            ));
    }

    /**
     * Crea la cita con la misma lógica que la web: nunca se cruzan dos citas. Si el paciente es nuevo,
     * se registra en la misma transacción (si el horario ya se ocupó, no queda un paciente suelto).
     *
     * @param  array<string, mixed>  $data
     */
    public static function book(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
            $patient = ($data['new_patient'] ?? false)
                ? app(PatientRegistrar::class)->register(array_filter($data['patient'] ?? [], fn ($value) => $value !== null && $value !== ''))
                : Patient::findOrFail($data['patient_id']);

            $planItem = filled($data['treatment_plan_item_id'] ?? null)
                ? app(TreatmentPlanService::class)->workableItems($patient)->firstWhere('id', (int) $data['treatment_plan_item_id'])
                : null;

            $appointment = app(AppointmentService::class)->bookFromStaff(
                auth()->user(),
                $patient,
                Doctor::findOrFail($data['doctor_id']),
                Treatment::findOrFail($data['treatment_id']),
                SlotPicker::startsAt($data),
                [
                    'origin' => $data['origin'] ?? null,
                    'fixed_date' => (bool) ($data['fixed_date'] ?? false),
                    'internal_notes' => $data['internal_notes'] ?? null,
                    'treatment_plan_item_id' => $planItem?->getKey(),
                ],
            );

            // Si agenda una sesión del plan, el paciente lo aceptó.
            if ($planItem) {
                app(TreatmentPlanService::class)->accept($planItem->plan);
            }

            return $appointment;
        });
    }

    /**
     * Lo que llega puesto: el doctor (el propio, el que se tocó o el único), el día y la hora.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function defaults(array $arguments): array
    {
        /** @var User $user */
        $user = auth()->user();
        $date = (string) ($arguments['date'] ?? '');
        $time = (string) ($arguments['time'] ?? '');
        $activeDoctors = Doctor::query()->active()->pluck('id');

        $doctorId = $user->managesAllAgendas()
            ? ($activeDoctors->contains((int) ($arguments['doctor'] ?? 0)) ? (int) $arguments['doctor'] : null)
            : $user->doctorId();

        return [
            'new_patient' => false,
            'patient_id' => filled($arguments['patient'] ?? null) ? (int) $arguments['patient'] : null,
            'patient' => ['relationship' => PatientRelationship::Self->value],
            'doctor_id' => $doctorId ?? ($activeDoctors->count() === 1 ? $activeDoctors->first() : null),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && CarbonImmutable::parse($date)->gte(today())
                ? $date
                : today()->toDateString(),
            'preferred_time' => preg_match('/^\d{2}:\d{2}$/', $time) ? $time : null,
            'manual' => false,
            'fixed_date' => false,
        ];
    }
}
