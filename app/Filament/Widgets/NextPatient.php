<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\ClinicalAlerts;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\User;
use App\Services\Dashboard\DoctorDay;
use App\Support\Dates;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;

/**
 * Para el doctor: su próximo paciente con lo que tiene que ver antes de llamarlo (alergias,
 * enfermedades, embarazo, qué se le hizo la última vez) y "Atender"; y las atenciones que dejó abiertas.
 */
class NextPatient extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.next-patient';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->doctorId() !== null && $user->canSeeClinical();
    }

    /** El mismo "Atender" de la lista: abre la atención con su plantilla y odontograma. */
    public function startAction(): Action
    {
        return AppointmentActions::start()
            ->record(fn (array $arguments) => AppointmentResource::getEloquentQuery()->find($arguments['appointment'] ?? null));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $day = new DoctorDay($user->doctorId());
        $next = $day->next();
        $waiting = $day->waiting();

        return [
            'next' => $next ? $this->nextData($day, $next) : null,
            'othersWaiting' => max(0, $waiting - ($next?->status === AppointmentStatus::CheckedIn ? 1 : 0)),
            'open' => $day->openConsultations()->map(function (Consultation $consultation) {
                $since = $consultation->attended_at ?? $consultation->created_at;

                return [
                    'name' => $consultation->patient->full_name,
                    'detail' => collect([
                        $consultation->appointment?->treatment?->name,
                        'abierta '.($since->isToday() ? 'hoy a las '.$since->format('H:i') : $since->diffForHumans()),
                    ])->filter()->implode(' · '),
                    'url' => ConsultationResource::urlFor($consultation),
                ];
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function nextData(DoctorDay $day, Appointment $appointment): array
    {
        $patient = $appointment->patient;
        $last = $day->lastVisit($appointment);
        $waiting = $appointment->waitingMinutes();

        return [
            'id' => $appointment->getKey(),
            'time' => $appointment->starts_at->format('H:i'),
            'name' => $patient->full_name,
            'age' => $patient->age !== null ? $patient->age.($patient->age === 1 ? ' año' : ' años') : null,
            'reason' => $appointment->planItem?->label() ?? $appointment->treatment->name,
            'state' => match (true) {
                $waiting !== null => 'En sala hace '.Dates::duration($waiting),
                $appointment->status === AppointmentStatus::Confirmed => 'Confirmó, aún no llega',
                default => 'Aún no confirma',
            },
            'stateColor' => match (true) {
                $waiting !== null => $waiting >= TodayAppointments::LONG_WAIT_MINUTES ? 'danger' : 'warning',
                $appointment->status === AppointmentStatus::Confirmed => 'success',
                default => 'gray',
            },
            'hasBackground' => $patient->medicalBackground !== null,
            'alerts' => ClinicalAlerts::for($patient),
            'last' => $last ? $last->treatment->name.' · '.$last->starts_at->translatedFormat('j M Y') : null,
            'notes' => $appointment->patient_note,
            'historyUrl' => PatientResource::getUrl('clinical', ['record' => $patient]),
        ];
    }
}
