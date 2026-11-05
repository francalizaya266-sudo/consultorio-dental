<?php

namespace App\Filament\Widgets;

use App\Enums\MessageTemplateKey;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Installments\InstallmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Dashboard\FollowUps;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Size;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * "Por hacer hoy" de recepción, al costado de la agenda: primero lo que espera un clic (avisos por
 * enviar, solicitudes de cambio) y después a quién escribirle o llamar, con el WhatsApp ya redactado
 * y "Nueva cita" a un toque. Cada grupo se abre y se cierra, con su número a la vista, y lleva a su
 * lista completa. Quien ya recibió el aviso hace poco queda marcado, para no escribirle dos veces.
 */
class FollowUpsToday extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Mientras carga, un recuadro del mismo alto: la página no salta. */
    protected ?string $placeholderHeight = '24rem';

    protected string $view = 'filament.widgets.follow-ups-today';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->managesAllAgendas();
    }

    /** Abre WhatsApp con el aviso del grupo para ese paciente, listo para retocar y enviar. */
    public function whatsappAction(): Action
    {
        return SendWhatsAppAction::compose('whatsapp', function (array $arguments) {
            $key = MessageTemplateKey::tryFrom((string) ($arguments['template'] ?? ''));
            $patient = Patient::query()->find($arguments['patient'] ?? null);

            return $key && $patient ? app(FollowUps::class)->draft($key, $patient) : null;
        })
            ->label('WhatsApp')
            ->link()
            ->size(Size::Small);
    }

    /**
     * @return array{sections: list<array{key: string, title: string, hint: string, count: int, url: string|null, summary: string|null, template: string|null, rows: list<array{patient_id: int, name: string, detail: string, contacted: string|null, can_message: bool, url: string, new_appointment_url: string|null}>}>}
     */
    protected function getViewData(): array
    {
        $followUps = app(FollowUps::class);

        return ['sections' => array_values(array_filter([
            $this->dueMessages(),
            $this->pendingRequests(),
            $this->awaitingAnswer($followUps),
            $this->unscheduled($followUps),
            $this->installments($followUps),
            $this->checkups($followUps),
            $this->birthdays($followUps),
        ], fn (?array $section) => $section !== null && $section['count'] > 0))];
    }

    /**
     * Los avisos que esperan su clic en "Avisos por enviar" (recordatorios, confirmaciones).
     *
     * @return array<string, mixed>
     */
    private function dueMessages(): array
    {
        return [
            'key' => 'messages',
            'title' => 'Avisos por enviar',
            'hint' => 'Recordatorios y confirmaciones listos para WhatsApp',
            'count' => WhatsappMessageResource::getEloquentQuery()->due()->count(),
            'url' => WhatsappMessageResource::getUrl('index'),
            'summary' => null,
            'template' => null,
            'rows' => [],
        ];
    }

    /**
     * Pacientes que pidieron otro horario desde su enlace y esperan respuesta.
     *
     * @return array<string, mixed>
     */
    private function pendingRequests(): array
    {
        return [
            'key' => 'requests',
            'title' => 'Solicitudes de cambio',
            'hint' => 'Elige uno de los horarios que propuso el paciente',
            'count' => RescheduleRequestResource::getEloquentQuery()->where('status', RescheduleRequestStatus::Pending)->count(),
            'url' => RescheduleRequestResource::getUrl('index'),
            'summary' => null,
            'template' => null,
            'rows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function awaitingAnswer(FollowUps $followUps): array
    {
        ['count' => $count, 'plans' => $plans] = $followUps->plansAwaitingAnswer();
        $key = MessageTemplateKey::PlanFollowUp;
        $contacted = $followUps->recentlyContacted($plans->pluck('patient_id'), $key);

        return [
            'key' => 'proposed',
            'title' => 'Presupuestos sin respuesta',
            'hint' => 'Presentados hace más de '.TreatmentPlan::ANSWER_WAIT_DAYS.' días',
            'count' => $count,
            'url' => PatientResource::getUrl('index', ['tab' => 'proposed']),
            'summary' => null,
            'template' => $key->value,
            'rows' => $plans->map(fn (TreatmentPlan $plan) => $this->row(
                $plan->patient,
                collect([Money::format($plan->total()), $plan->doctor?->name, 'presentado '.$plan->proposed_at->diffForHumans()])->filter()->implode(' · '),
                $contacted,
            ))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function unscheduled(FollowUps $followUps): array
    {
        ['count' => $count, 'patients' => $patients] = $followUps->unscheduledPlans();
        $key = MessageTemplateKey::PlanNextSession;
        $contacted = $followUps->recentlyContacted($patients->modelKeys(), $key);

        return [
            'key' => 'unscheduled',
            'title' => 'Planes sin próxima cita',
            'hint' => 'Aceptaron el plan y les falta terminarlo',
            'count' => $count,
            'url' => PatientResource::getUrl('index', ['tab' => 'unscheduled']),
            'summary' => null,
            'template' => $key->value,
            'rows' => $patients->map(function (Patient $patient) use ($contacted) {
                /** @var Collection<int, TreatmentPlanItem> $items */
                $items = $patient->treatmentPlans->flatMap->items;
                // Recepción ve el procedimiento, no la pieza (Ley 29733).
                $labels = $items->map(fn (TreatmentPlanItem $item) => $item->label(withTooth: false))->unique()->values();

                return $this->row(
                    $patient,
                    'Falta: '.$labels->take(2)->implode(', ').($labels->count() > 2 ? ' y '.($labels->count() - 2).' más' : ''),
                    $contacted,
                    AppointmentResource::getUrl('create', ['patient' => $patient->getKey(), 'plan_item' => $items->first()?->getKey()]),
                );
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function installments(FollowUps $followUps): array
    {
        ['count' => $count, 'amount' => $amount] = $followUps->lateInstallments();

        return [
            'key' => 'installments',
            'title' => 'Cuotas atrasadas',
            'hint' => 'Vencieron y no se pagaron',
            'count' => $count,
            'url' => InstallmentResource::getUrl('index', ['tab' => 'late']),
            'summary' => Money::format($amount).' por cobrar. En la lista, "Escribir" prepara el aviso de cada una.',
            'template' => null,
            'rows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function checkups(FollowUps $followUps): array
    {
        ['count' => $count, 'patients' => $patients] = $followUps->checkups();
        $key = MessageTemplateKey::CheckupRecall;
        $contacted = $followUps->recentlyContacted($patients->modelKeys(), $key);

        return [
            'key' => 'checkup',
            'title' => 'Para su control',
            'hint' => 'Hace 6 meses o más que no vienen y no tienen cita',
            'count' => $count,
            'url' => PatientResource::getUrl('index', ['tab' => 'checkup']),
            'summary' => null,
            'template' => $key->value,
            'rows' => $patients->map(fn (Patient $patient) => $this->row(
                $patient,
                'Última visita: '.$patient->last_visit_at->translatedFormat('j M Y'),
                $contacted,
                AppointmentResource::getUrl('create', ['patient' => $patient->getKey()]),
            ))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function birthdays(FollowUps $followUps): array
    {
        $patients = $followUps->birthdays();
        $key = MessageTemplateKey::Birthday;
        $contacted = $followUps->recentlyContacted($patients->modelKeys(), $key);

        return [
            'key' => 'birthdays',
            'title' => 'Cumpleaños de hoy',
            'hint' => 'Un saludo cuesta poco y se recuerda',
            'count' => $patients->count(),
            'url' => null,
            'summary' => null,
            'template' => $key->value,
            'rows' => $patients->map(fn (Patient $patient) => $this->row(
                $patient,
                'Cumple '.$patient->age.($patient->age === 1 ? ' año' : ' años'),
                $contacted,
            ))->all(),
        ];
    }

    /**
     * @param  array<int, CarbonImmutable>  $contacted
     * @return array{patient_id: int, name: string, detail: string, contacted: string|null, can_message: bool, url: string, new_appointment_url: string|null}
     */
    private function row(Patient $patient, string $detail, array $contacted, ?string $newAppointmentUrl = null): array
    {
        $sentAt = $contacted[$patient->getKey()] ?? null;

        return [
            'patient_id' => $patient->getKey(),
            'name' => $patient->full_name,
            'detail' => $detail,
            'contacted' => $sentAt ? 'Le escribiste '.($sentAt->isToday() ? 'hoy' : $sentAt->diffForHumans()) : null,
            'can_message' => $patient->account !== null,
            'url' => PatientResource::getUrl('view', ['record' => $patient]),
            'new_appointment_url' => $newAppointmentUrl,
        ];
    }
}
