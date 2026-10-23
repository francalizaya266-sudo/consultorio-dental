<?php

namespace App\Services\Clinical;

use App\Enums\MessageTemplateKey;
use App\Enums\PlanItemStatus;
use App\Enums\ToothState;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\ClinicalException;
use App\Models\Consultation;
use App\Models\Odontogram;
use App\Models\OdontogramEntry;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plan de tratamiento con costos: se arma solo desde los hallazgos en rojo del odontograma,
 * el paciente lo acepta y su avance se actualiza con cada atención en que se trabaja.
 */
final class TreatmentPlanService
{
    /** Hallazgo en rojo del odontograma → tratamiento que se propone (por el slug del catálogo). */
    public const TREATMENT_FOR = [
        'caries' => 'curacion-con-resina',
        'restauracion' => 'curacion-con-resina',
        'restauracion_temporal' => 'curacion-con-resina',
        'defecto_esmalte' => 'curacion-con-resina',
        'fractura' => 'curacion-con-resina',
        'extraccion_indicada' => 'extraccion-simple',
        'remanente_radicular' => 'extraccion-simple',
        'tratamiento_pulpar' => 'endodoncia',
    ];

    /** Orden habitual para escribir las superficies: MOD, luego V y P/L. */
    private const SURFACE_ORDER = ['M', 'O', 'I', 'D', 'V', 'P', 'L'];

    public function __construct(private readonly WhatsAppOutbox $outbox) {}

    /**
     * Procedimientos que sugiere el odontograma: uno por pieza y tratamiento, con sus superficies
     * juntas y el precio del catálogo. No repite lo que ya está en un plan vigente del paciente.
     *
     * @return list<array<string, mixed>>
     */
    public function suggestFromOdontogram(?Odontogram $odontogram): array
    {
        if (! $odontogram) {
            return [];
        }

        $treatments = Treatment::query()->whereIn('slug', array_unique(self::TREATMENT_FOR))->get()->keyBy('slug');
        $planned = $this->plannedKeys($odontogram->patient_id);

        return $odontogram->entries()
            ->with('condition')
            ->where('state', ToothState::Bad)
            ->orderBy('tooth')
            ->get()
            ->map(fn (OdontogramEntry $entry) => ['entry' => $entry, 'treatment' => $treatments[self::TREATMENT_FOR[$entry->condition->code] ?? ''] ?? null])
            ->filter(fn (array $pair) => $pair['treatment'] !== null)
            ->groupBy(fn (array $pair) => $pair['treatment']->getKey().'-'.$pair['entry']->tooth)
            ->reject(fn (Collection $group, string $key) => in_array($key, $planned, true))
            ->map(function (Collection $group) {
                /** @var Treatment $treatment */
                $treatment = $group->first()['treatment'];
                $surfaces = $group->pluck('entry.surface')->filter()->unique()
                    ->sortBy(fn (string $surface) => array_search($surface, self::SURFACE_ORDER, true))
                    ->implode('');

                return [
                    ...$this->itemFor($treatment),
                    'tooth' => (string) $group->first()['entry']->tooth,
                    'surfaces' => $surfaces ?: null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> Renglón del plan para un tratamiento del catálogo. */
    public function itemFor(Treatment $treatment): array
    {
        return [
            'treatment_id' => $treatment->getKey(),
            'description' => $treatment->name,
            'tooth' => null,
            'surfaces' => null,
            'quantity' => 1,
            'unit_price' => (float) ($treatment->price_from ?? 0),
            'discount' => 0,
            'sessions_planned' => 1,
        ];
    }

    /**
     * Arma (o completa) el borrador del plan del doctor con lo que sugiere el odontograma de la atención.
     *
     * @return array{0: TreatmentPlan|null, 1: int} El plan y cuántos procedimientos se agregaron.
     */
    public function draftFromConsultation(Consultation $consultation, User $by): array
    {
        if ($by->doctorId() !== $consultation->doctor_id) {
            throw ClinicalException::notAuthor();
        }

        $items = $this->suggestFromOdontogram($consultation->odontogram);

        if ($items === []) {
            return [null, 0];
        }

        return DB::transaction(function () use ($consultation, $items) {
            $plan = TreatmentPlan::query()
                ->where('patient_id', $consultation->patient_id)
                ->where('doctor_id', $consultation->doctor_id)
                ->where('status', TreatmentPlanStatus::Draft)
                ->latest('id')
                ->first()
                ?? TreatmentPlan::create([
                    'patient_id' => $consultation->patient_id,
                    'doctor_id' => $consultation->doctor_id,
                    'consultation_id' => $consultation->getKey(),
                    'title' => 'Plan de tratamiento',
                    'status' => TreatmentPlanStatus::Draft,
                ]);

            $next = (int) $plan->items()->max('sort_order');

            foreach ($items as $item) {
                $plan->items()->create([...$item, 'sort_order' => ++$next]);
            }

            return [$plan, count($items)];
        });
    }

    /** Se entregó al paciente (impreso o por WhatsApp): el borrador pasa a "presentado". */
    public function markProposed(TreatmentPlan $plan): void
    {
        if ($plan->status === TreatmentPlanStatus::Draft) {
            $plan->update(['status' => TreatmentPlanStatus::Proposed]);
        }
    }

    public function accept(TreatmentPlan $plan): void
    {
        if (! in_array($plan->status, [TreatmentPlanStatus::Draft, TreatmentPlanStatus::Proposed], true)) {
            return;
        }

        $plan->update(['status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()]);
    }

    /** El plan no se borra: queda cancelado con lo que se llegó a hacer. */
    public function cancel(TreatmentPlan $plan, ?string $reason = null): void
    {
        $plan->update([
            'status' => TreatmentPlanStatus::Cancelled,
            'notes' => trim(collect([$plan->notes, $reason ? 'Cancelado: '.$reason : null])->filter()->implode("\n")) ?: null,
        ]);
    }

    /** Texto del presupuesto para WhatsApp: un renglón por procedimiento con su precio. */
    public function text(TreatmentPlan $plan): string
    {
        return $plan->items
            ->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)
            ->map(fn (TreatmentPlanItem $item) => '• '.$item->label().($item->quantity > 1 ? " × {$item->quantity}" : '').': '.Money::format($item->subtotal()))
            ->implode("\n");
    }

    /** Presupuesto para el celular del paciente (o del titular). Null si no tiene celular. */
    public function whatsappDraft(TreatmentPlan $plan): ?WhatsappMessage
    {
        $plan->loadMissing('patient.account', 'doctor', 'items');
        $account = $plan->patient->account;

        if (! $account) {
            return null;
        }

        return $this->outbox->draftForAccount($account, MessageTemplateKey::TreatmentPlan, [
            'nombre' => $plan->patient->first_name,
            'paciente' => $plan->patient->full_name,
            'doctor' => $plan->doctor->name,
            'plan' => $this->text($plan),
            'total' => Money::format($plan->total()),
        ], $plan->patient_id);
    }

    /** Procedimientos que se pueden trabajar en una atención: los pendientes de los planes vigentes. */
    public function workableItems(Patient $patient, ?Consultation $consultation = null): Collection
    {
        return TreatmentPlanItem::query()
            ->with('plan')
            ->whereHas('plan', fn ($query) => $query->open()->where('patient_id', $patient->getKey()))
            ->where(fn ($query) => $query
                ->whereIn('status', [PlanItemStatus::Pending, PlanItemStatus::InProgress])
                ->when($consultation, fn ($query) => $query->orWhereHas('consultations', fn ($c) => $c->whereKey($consultation->getKey()))))
            ->orderBy('treatment_plan_id')
            ->orderBy('sort_order')
            ->get();
    }

    /** Recalcula el avance de los procedimientos de la atención (al terminarla o reabrirla). */
    public function syncConsultation(Consultation $consultation): void
    {
        $this->syncItems($consultation->planItems()->with('plan')->get());
    }

    /**
     * Sesiones hechas = atenciones terminadas en que se trabajó el procedimiento.
     * El estado del procedimiento y del plan se deducen de ahí.
     *
     * @param  iterable<TreatmentPlanItem>  $items
     */
    public function syncItems(iterable $items): void
    {
        $plans = collect();

        foreach ($items as $item) {
            $done = $item->consultations()->whereNotNull('closed_at')->count();

            $item->sessions_done = min($done, 255);

            if ($item->status !== PlanItemStatus::Cancelled) {
                $item->status = match (true) {
                    $done >= max(1, $item->sessions_planned) => PlanItemStatus::Done,
                    $done > 0 => PlanItemStatus::InProgress,
                    default => PlanItemStatus::Pending,
                };
            }

            $item->save();
            $plans->put($item->treatment_plan_id, $item->loadMissing('plan')->plan);
        }

        $plans->each(fn (TreatmentPlan $plan) => $this->syncPlan($plan));
    }

    private function syncPlan(TreatmentPlan $plan): void
    {
        if ($plan->status === TreatmentPlanStatus::Cancelled) {
            return;
        }

        $items = $plan->items()->whereNot('status', PlanItemStatus::Cancelled)->get();

        if ($items->isNotEmpty() && $items->every(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Done)) {
            $plan->update(['status' => TreatmentPlanStatus::Completed, 'completed_at' => $plan->completed_at ?? now(), 'accepted_at' => $plan->accepted_at ?? now()]);
        } elseif ($items->contains(fn (TreatmentPlanItem $item) => $item->sessions_done > 0)) {
            // Si ya se está trabajando, el paciente lo aceptó.
            $plan->update(['status' => TreatmentPlanStatus::InProgress, 'completed_at' => null, 'accepted_at' => $plan->accepted_at ?? now()]);
        } elseif (in_array($plan->status, [TreatmentPlanStatus::InProgress, TreatmentPlanStatus::Completed], true)) {
            $plan->update(['status' => TreatmentPlanStatus::Accepted, 'completed_at' => null]);
        }
    }

    /** @return list<string> "tratamiento-pieza" de lo que ya está en planes vigentes. */
    private function plannedKeys(int $patientId): array
    {
        return TreatmentPlanItem::query()
            ->whereHas('plan', fn ($query) => $query->open()->where('patient_id', $patientId))
            ->whereNot('status', PlanItemStatus::Cancelled)
            ->get(['treatment_id', 'tooth'])
            ->map(fn (TreatmentPlanItem $item) => $item->treatment_id.'-'.$item->tooth)
            ->all();
    }
}
