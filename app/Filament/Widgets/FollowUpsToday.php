<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Pages\TomorrowTray;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Installments\InstallmentResource;
use App\Filament\Resources\LabOrders\LabOrderResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Appointment;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Supply;
use App\Models\SupplyBatch;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Dashboard\FollowUps;
use App\Services\Inventory\SupplyForecast;
use App\Services\Inventory\Tray;
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
            $this->leftOpenAppointments(),
            $this->dueMessages(),
            $this->pendingRequests(),
            $this->awaitingAnswer($followUps),
            $this->unscheduled($followUps),
            $this->missedVisits($followUps),
            $this->installments($followUps),
            $this->labOrders(),
            $this->suppliesToReorder(),
            $this->suppliesRunningLow(),
            $this->tomorrowTray(),
            $this->batchesExpiring(),
            $this->checkups($followUps),
            $this->birthdays($followUps),
            $this->reviews($followUps),
        ], fn (?array $section) => $section !== null && $section['count'] > 0))];
    }

    /**
     * Citas de días pasados que quedaron sin cerrar. Sin cerrarlas, no se cobra lo atendido,
     * no se descuentan los materiales y las inasistencias del reporte salen mal.
     *
     * @return array<string, mixed>
     */
    private function leftOpenAppointments(): array
    {
        return [
            'key' => 'left-open',
            'title' => 'Citas sin cerrar',
            'hint' => 'De días pasados: marca si vino o si no asistió',
            'count' => AppointmentResource::getEloquentQuery()->leftOpen()->count(),
            'url' => AppointmentResource::getUrl('index', ['tab' => 'unclosed']),
            'summary' => null,
            'template' => null,
            'rows' => [],
        ];
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

    /**
     * Faltaron o cancelaron y nadie les dio otro horario: se les escribe o se les agenda de nuevo lo mismo.
     *
     * @return array<string, mixed>
     */
    private function missedVisits(FollowUps $followUps): array
    {
        ['count' => $count, 'patients' => $patients] = $followUps->missedVisits();
        $key = MessageTemplateKey::MissedVisit;
        $contacted = $followUps->recentlyContacted($patients->modelKeys(), $key);

        return [
            'key' => 'missed',
            'title' => 'Faltaron o cancelaron',
            'hint' => 'En los últimos '.intdiv(Patient::MISSED_VISIT_DAYS, 30).' meses y siguen sin otra cita',
            'count' => $count,
            'url' => PatientResource::getUrl('index', ['tab' => 'missed']),
            'summary' => null,
            'template' => $key->value,
            'rows' => $patients->map(function (Patient $patient) use ($contacted) {
                /** @var Appointment $missed */
                $missed = $patient->appointments->first();

                return $this->row(
                    $patient,
                    ($missed->status === AppointmentStatus::NoShow ? 'No asistió el ' : 'Se canceló su cita del ').$missed->starts_at->translatedFormat('j \d\e F').' · '.$missed->treatment->name,
                    $contacted,
                    AppointmentResource::getUrl('create', ['patient' => $patient->getKey(), 'treatment' => $missed->treatment_id, 'doctor' => $missed->doctor_id]),
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

    /**
     * Trabajos de laboratorio que piden algo hoy: se atrasaron, llegan hoy, el paciente tiene cita antes de la
     * entrega, o ya llegaron y nadie lo citó para colocarlos.
     *
     * @return array<string, mixed>
     */
    private function labOrders(): array
    {
        $orders = LabOrderResource::getEloquentQuery()->needingAttention()->orderBy('due_on')->get();

        return [
            'key' => 'lab',
            'title' => 'Trabajos de laboratorio',
            'hint' => 'Atrasados, llegan hoy, con la cita antes de la entrega o falta citar',
            'count' => $orders->count(),
            'url' => LabOrderResource::getUrl('index', ['tab' => 'attention']),
            'summary' => self::firstNames($orders->map(fn (LabOrder $order) => $order->description.' de '.$order->patient->full_name.' ('.mb_strtolower(mb_substr((string) $order->alert(), 0, 1)).mb_substr((string) $order->alert(), 1).')')).'.',
            'template' => null,
            'rows' => [],
        ];
    }

    /**
     * Materiales que bajaron del mínimo y nadie pidió todavía: el pedido sale por WhatsApp desde Inventario.
     *
     * @return array<string, mixed>|null
     */
    private function suppliesToReorder(): ?array
    {
        if (! auth()->user()->can('viewAny', Supply::class)) {
            return null;
        }

        // Lo que ya se pidió espera en Compras: aquí queda solo lo que falta pedir.
        $names = Supply::query()->toReorder()->orderBy('name')->pluck('name');

        return [
            'key' => 'supplies',
            'title' => 'Materiales por pedir',
            'hint' => 'Bajaron del mínimo y no se pidieron',
            'count' => $names->count(),
            'url' => SupplyResource::getUrl('index', ['tab' => 'low']),
            'summary' => self::firstNames($names).'. Pídelos por WhatsApp desde Inventario.',
            'template' => null,
            'rows' => [],
        ];
    }

    /**
     * Materiales que todavía alcanzan, pero que con las citas de las próximas dos semanas se acaban o bajan del
     * mínimo: se piden antes de que falten.
     *
     * @return array<string, mixed>|null
     */
    private function suppliesRunningLow(): ?array
    {
        if (! auth()->user()->can('viewAny', Supply::class)) {
            return null;
        }

        $lines = app(SupplyForecast::class)->runningLow();

        return [
            'key' => 'supplies-soon',
            'title' => 'Materiales que se acaban pronto',
            'hint' => 'Con las citas de las próximas '.intdiv(SupplyForecast::HORIZON_DAYS, 7).' semanas',
            'count' => $lines->count(),
            'url' => SupplyResource::getUrl('index', ['tab' => 'soon']),
            'summary' => self::firstNames($lines->map(fn (array $line) => $line['supply']->name.' ('.($line['runs_out_on'] ? 'se acaba ~'.$line['runs_out_on']->format('d/m') : 'baja del mínimo el '.$line['below_min_on']->format('d/m')).')')).'. Pídelos antes de que falten.',
            'template' => null,
            'rows' => [],
        ];
    }

    /**
     * La bandeja de mañana: cuántas citas llevan materiales y si a alguna no le alcanza. Se prepara la víspera.
     *
     * @return array<string, mixed>|null
     */
    private function tomorrowTray(): ?array
    {
        if (! TomorrowTray::canAccess()) {
            return null;
        }

        $visits = collect(app(Tray::class)->for(today()->addDay()))->filter(fn (array $visit) => $visit['items'] !== []);
        $short = $visits->flatMap(fn (array $visit) => array_filter($visit['items'], fn (array $item) => $item['short'] !== null))->pluck('name')->unique();

        return [
            'key' => 'tray',
            'title' => 'Bandeja de mañana',
            'hint' => 'Lo que se usa en cada cita, del lote que vence primero',
            'count' => $visits->count(),
            'url' => TomorrowTray::getUrl(),
            'summary' => $short->isNotEmpty() ? 'No alcanza: '.self::firstNames($short).'.' : null,
            'template' => null,
            'rows' => [],
        ];
    }

    /**
     * Lotes que vencen en 30 días o ya vencieron: se usan primero o se dan de baja.
     *
     * @return array<string, mixed>|null
     */
    private function batchesExpiring(): ?array
    {
        if (! auth()->user()->can('viewAny', Supply::class)) {
            return null;
        }

        $batches = SupplyBatch::query()
            ->available()
            ->expiringSoon()
            ->whereHas('supply', fn ($supply) => $supply->active())
            ->with('supply')
            ->orderBy('expires_on')
            ->get();
        $expired = $batches->filter->isExpired()->count();

        return [
            'key' => 'batches',
            'title' => 'Lotes por vencer',
            'hint' => 'Vencen en '.Supply::EXPIRY_WARNING_DAYS.' días o menos',
            'count' => $batches->count(),
            'url' => SupplyResource::getUrl('index', ['tab' => 'expiring']),
            'summary' => self::firstNames($batches->map(fn (SupplyBatch $batch) => $batch->supply->name.' ('.($batch->isExpired() ? 'venció' : 'vence').' el '.$batch->expires_on->format('d/m').')')->unique())
                .($expired > 0 ? '. Lo vencido se da de baja.' : '. Úsalos primero.'),
            'template' => null,
            'rows' => [],
        ];
    }

    /** @param  Collection<int, string>  $names  "Guantes, Lidocaína y 3 más". */
    private static function firstNames(Collection $names): string
    {
        $names = $names->values();

        return $names->take(2)->implode(', ').($names->count() > 2 ? ' y '.($names->count() - 2).' más' : '');
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
            'hint' => 'Sin cita y ya les toca: ortodoncia cada mes, implante al año, lo demás a los 6 meses',
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
     * Al terminar la atención, a quien vino contento: viene seguido, está al día y no faltó. Es el mejor día para
     * pedirle su opinión en Google (y a nadie más de una vez cada seis meses).
     *
     * @return array<string, mixed>
     */
    private function reviews(FollowUps $followUps): array
    {
        $patients = $followUps->reviewCandidates();
        $key = MessageTemplateKey::ReviewRequest;
        $contacted = $followUps->recentlyContacted($patients->modelKeys(), $key);

        return [
            'key' => 'reviews',
            'title' => 'Pedir su opinión en Google',
            'hint' => 'Atendidos hoy que vienen seguido, están al día y no faltaron',
            'count' => $patients->count(),
            'url' => null,
            'summary' => null,
            'template' => $key->value,
            'rows' => $patients->map(fn (Patient $patient) => $this->row(
                $patient,
                collect(['Hoy: '.$patient->appointments->first()?->treatment->name, $patient->visits_count.' atenciones'])->implode(' · '),
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
