<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\TodayView;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Concerns\RefreshesWithToday;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\ClinicalAlerts;
use App\Filament\Support\VisitFlags;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Clinical\ConsentService;
use App\Services\Dashboard\DoctorDay;
use App\Support\Dates;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;

/**
 * Para quien atiende, en "Mi consulta": su próximo paciente con lo que tiene que ver antes de llamarlo
 * (alergias, enfermedades, embarazo, qué se le hizo la última vez), "Atender" y quién viene después. Las
 * atenciones que dejó abiertas van en su propia tarjeta, al costado (OpenConsultations).
 */
class NextPatient extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RefreshesWithToday;

    /** Tu próximo paciente se ve apenas abre "Hoy", sin un recuadro vacío mientras carga. */
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.next-patient';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->doctorId() !== null && $user->canSeeClinical() && TodayView::current($user) === TodayView::Mine;
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
        $upcoming = $day->upcoming();
        $next = $upcoming->get(0);
        $after = $upcoming->get(1);
        $waiting = $day->waiting();

        return [
            'next' => $next ? $this->nextData($day, $next) : null,
            // Quién viene después, para saberlo sin bajar a la agenda: "11:30 Ana Silva · Evaluación".
            'after' => $after ? [
                'time' => $after->starts_at->format('H:i'),
                'text' => collect([
                    $after->patient->full_name,
                    $after->planItem?->label() ?? $after->treatment->name,
                    $after->status === AppointmentStatus::CheckedIn ? 'ya está en sala' : null,
                ])->filter()->implode(' · '),
            ] : null,
            'othersWaiting' => max(0, $waiting - ($next?->status === AppointmentStatus::CheckedIn ? 1 : 0)),
        ];
    }

    /** @return array<string, mixed> */
    private function nextData(DoctorDay $day, Appointment $appointment): array
    {
        $patient = $appointment->patient;
        $last = $day->lastVisit($appointment);
        $waiting = $appointment->waitingMinutes();
        // Minutos desde su hora, si ya pasó y todavía no llega (con 5 de tolerancia).
        $late = (int) $appointment->starts_at->diffInMinutes(now());
        $late = $waiting === null && $late >= 5 ? $late : null;

        return [
            'id' => $appointment->getKey(),
            'time' => $appointment->starts_at->format('H:i'),
            'name' => $patient->full_name,
            'age' => $patient->age !== null ? $patient->age.($patient->age === 1 ? ' año' : ' años') : null,
            'reason' => collect([
                $appointment->planItem?->label() ?? $appointment->treatment->name,
                mb_strtolower(VisitFlags::session($appointment->planItem) ?? ''),
            ])->filter()->implode(' · '),
            'state' => match (true) {
                $waiting !== null => 'Llegó hace '.Dates::duration($waiting),
                $late !== null => 'Va '.Dates::duration($late).' tarde',
                $appointment->status === AppointmentStatus::Confirmed => 'Confirmó, aún no llega',
                default => 'Aún no confirma',
            },
            // Color solo para lo que pide atención: alguien esperando o que se atrasa. Lo demás, en gris.
            'stateColor' => match (true) {
                $waiting !== null => $waiting >= TodayAppointments::LONG_WAIT_MINUTES ? 'danger' : 'warning',
                $late !== null => 'warning',
                default => 'gray',
            },
            'hasBackground' => $patient->medicalBackground !== null,
            'alerts' => ClinicalAlerts::withSeverity($patient),
            // Si el tratamiento pide consentimiento y aún no lo firmó, se firma antes de empezar.
            'consentMissing' => app(ConsentService::class)->signedForVisit($appointment) === false,
            'last' => $last ? $last->treatment->name.' · '.$last->starts_at->translatedFormat('j M Y') : null,
            'notes' => $appointment->patient_note,
            // Lo que recepción (o el mismo doctor) anotó al agendar: "viene con dolor", "trae su radiografía".
            'internalNotes' => $appointment->internal_notes,
            'historyUrl' => PatientResource::getUrl('clinical', ['record' => $patient]),
        ];
    }
}
