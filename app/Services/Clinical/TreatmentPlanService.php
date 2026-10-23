<?php

namespace App\Services\Clinical;

use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Enums\PlanItemStatus;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\ClinicalException;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPackageItem;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanInstallment;
use App\Models\TreatmentPlanItem;
use App\Models\TreatmentPlanSignature;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Billing\BillingService;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Money;
use App\Support\Signatures;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan de tratamiento con costos: se arma desde lo que el doctor elige de las sugerencias del
 * odontograma, el paciente lo acepta firmando (y con eso su precio ya no cambia) y elige cómo
 * pagarlo; su avance se actualiza con cada atención en que se trabaja.
 */
final class TreatmentPlanService
{
    /** Orden habitual para escribir las superficies: MOD, luego V y P/L. */
    public const SURFACE_ORDER = ['M', 'O', 'I', 'D', 'V', 'P', 'L'];

    /** Cuotas como máximo en un plan. */
    public const MAX_INSTALLMENTS = 24;

    public function __construct(
        private readonly WhatsAppOutbox $outbox,
        private readonly BillingService $billing,
    ) {}

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
     * Los procedimientos de un paquete para el plan. El precio del paquete se reparte entre ellos según
     * su precio normal: cada uno lleva su precio de catálogo y el descuento que le toca, así el total
     * es exactamente el del paquete y cada sesión carga su parte.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsFromPackage(TreatmentPackage $package): array
    {
        $package->loadMissing('items.treatment');
        $items = $package->items->values();

        if ($items->isEmpty()) {
            return [];
        }

        $price = (float) $package->price;
        $weights = $items->map(fn (TreatmentPackageItem $item) => $item->normalPrice());

        // Sin precios en el catálogo, se reparte según la cantidad.
        if ($weights->sum() <= 0) {
            $weights = $items->map(fn (TreatmentPackageItem $item) => (float) $item->quantity);
        }

        $shares = $weights->map(fn (float $weight) => round($price * $weight / $weights->sum(), 2))->all();
        // Los céntimos del redondeo van al de mayor precio.
        $largest = array_search(max($shares), $shares, true);
        $shares[$largest] = round($price - (array_sum($shares) - $shares[$largest]), 2);

        return $items->map(function (TreatmentPackageItem $item, int $index) use ($package, $shares) {
            $share = $shares[$index];
            // Si el paquete cuesta más que el catálogo, el precio sube lo justo para cubrir su parte.
            $unitPrice = $share <= $item->normalPrice()
                ? (float) $item->treatment->price_from
                : ceil(round($share / $item->quantity * 100, 6)) / 100;

            return [
                'treatment_id' => $item->treatment_id,
                'treatment_package_id' => $package->getKey(),
                'description' => $item->description ?: $item->treatment->name,
                'tooth' => null,
                'surfaces' => null,
                'quantity' => $item->quantity,
                'unit_price' => $unitPrice,
                'discount' => round($item->quantity * $unitPrice - $share, 2),
                'sessions_planned' => $item->sessions_planned,
            ];
        })->all();
    }

    /**
     * Cómo se puede pagar un paquete, una línea por forma: "Pago único de S/ 4,600.00 (…)."
     *
     * @return list<string>
     */
    public static function packagePaymentOptions(TreatmentPackage $package): array
    {
        $price = (float) $package->price;
        $options = [];

        if ($package->allows_upfront) {
            $options[] = self::scheduleText(PlanPaymentMode::Upfront, self::schedule($price, PlanPaymentMode::Upfront, $package->terms(), today()), false);
        }

        if ($package->allows_installments) {
            try {
                $options[] = 'En cuotas: '.Str::lcfirst(self::scheduleText(PlanPaymentMode::Installments, self::schedule($price, PlanPaymentMode::Installments, $package->terms(), today()), false));
            } catch (ClinicalException $e) {
                $options[] = 'En cuotas: '.Str::lcfirst($e->getMessage());
            }
        }

        return $options ?: ['Por sesión: paga lo de cada sesión al terminarla.'];
    }

    /**
     * 2x1: el plan de la otra persona, con lo del paquete y sin costo, incluido en el plan de quien
     * paga. Si quien paga ya aceptó, el de la otra persona queda aceptado también.
     */
    public function addCompanion(TreatmentPlan $plan, TreatmentPackage $package, Patient $companion): TreatmentPlan
    {
        if (! $package->for_two || $companion->getKey() === $plan->patient_id) {
            throw ClinicalException::invalidCompanion();
        }

        return DB::transaction(function () use ($plan, $package, $companion) {
            $companionPlan = TreatmentPlan::create([
                'patient_id' => $companion->getKey(),
                'doctor_id' => $plan->doctor_id,
                'included_in_plan_id' => $plan->getKey(),
                'title' => $package->name.' (2x1)',
                'status' => $plan->status === TreatmentPlanStatus::Draft ? TreatmentPlanStatus::Draft : TreatmentPlanStatus::Proposed,
            ]);

            foreach ($this->itemsFromPackage($package) as $index => $item) {
                $companionPlan->items()->create([...$item, 'discount' => round($item['quantity'] * $item['unit_price'], 2), 'sort_order' => $index]);
            }

            if ($plan->accepted_at !== null) {
                $this->accept($companionPlan);
            }

            return $companionPlan;
        });
    }

    /**
     * Desde la atención: suma el paquete al borrador del plan y, si es 2x1, arma el plan de la otra persona.
     *
     * @return array{0: TreatmentPlan|null, 1: TreatmentPlan|null} El plan y el de la otra persona del 2x1.
     */
    public function addPackageToDraft(Consultation $consultation, User $by, TreatmentPackage $package, ?Patient $companion = null): array
    {
        return DB::transaction(function () use ($consultation, $by, $package, $companion) {
            [$plan] = $this->addToDraft($consultation, $by, $this->itemsFromPackage($package));

            if ($plan && $plan->title === 'Plan de tratamiento' && $plan->items()->whereNull('treatment_package_id')->doesntExist()) {
                $plan->update(['title' => $package->name]);
            }

            // Un 2x1 por plan: si ya tiene a la otra persona, el paquete solo se suma.
            $companionPlan = $plan && $package->for_two && $companion && $plan->companionPlans()->open()->doesntExist()
                ? $this->addCompanion($plan, $package, $companion)
                : null;

            return [$plan, $companionPlan];
        });
    }

    /**
     * Agrega procedimientos al borrador del plan del doctor (lo crea si no hay uno), desde la atención.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: TreatmentPlan|null, 1: int} El plan y cuántos procedimientos se agregaron.
     */
    public function addToDraft(Consultation $consultation, User $by, array $items): array
    {
        if ($by->doctorId() !== $consultation->doctor_id) {
            throw ClinicalException::notAuthor();
        }

        if ($items === []) {
            return [null, 0];
        }

        return DB::transaction(function () use ($consultation, $items) {
            // El plan que va incluido en un 2x1 queda como se armó: lo nuevo va en un plan aparte.
            $plan = TreatmentPlan::query()
                ->where('patient_id', $consultation->patient_id)
                ->where('doctor_id', $consultation->doctor_id)
                ->where('status', TreatmentPlanStatus::Draft)
                ->whereNull('included_in_plan_id')
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

    /** Aceptado sin firma (por ejemplo, al agendar una de sus sesiones): se paga por sesión y el precio queda fijo. */
    public function accept(TreatmentPlan $plan): void
    {
        if (! in_array($plan->status, [TreatmentPlanStatus::Draft, TreatmentPlanStatus::Proposed], true)) {
            return;
        }

        DB::transaction(function () use ($plan) {
            $plan->update(['status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now(), 'payment_mode' => $plan->payment_mode ?? PlanPaymentMode::PerSession]);
            $this->lockItems($plan, $plan->items()->whereNull('accepted_at')->whereNot('status', PlanItemStatus::Cancelled)->get(), false);
        });
    }

    /**
     * El paciente firma el plan y elige cómo pagarlo; o, si ya lo había aceptado, firma lo que se agregó
     * después (eso se paga por sesión). Lo firmado ya no cambia de precio. Si hoy vence la inicial o el
     * pago único, se carga.
     *
     * @param  array{payment_mode?: PlanPaymentMode|string|null, down_payment?: float|string|null, installments?: int|string|null, monthly_amount?: float|string|null, first_due_on?: mixed, upfront_discount?: float|string|null}  $terms
     * @param  array{signer_name: string, signer_document?: string|null, signer_relationship: PatientRelationship|string}  $signer
     */
    public function sign(TreatmentPlan $plan, array $terms, array $signer, string $signature, User $by): TreatmentPlanSignature
    {
        if (! $plan->isOpen()) {
            throw ClinicalException::nothingToAccept();
        }

        $plan->loadMissing('items');
        $firstTime = $plan->accepted_at === null;
        $items = $firstTime
            ? $plan->items->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)->values()
            : $plan->itemsToAccept();

        if ($items->isEmpty()) {
            throw ClinicalException::nothingToAccept();
        }

        $mode = $terms['payment_mode'] ?? null;
        $mode = $firstTime ? ($mode instanceof PlanPaymentMode ? $mode : PlanPaymentMode::tryFrom((string) $mode) ?? PlanPaymentMode::PerSession) : null;
        $total = round($items->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2);
        $schedule = $mode?->hasSchedule() ? self::schedule($total, $mode, $terms, today()) : [];
        $path = Signatures::store($signature, $plan->patient_id);

        $signed = DB::transaction(function () use ($plan, $items, $mode, $schedule, $total, $signer, $path, $by, $firstTime) {
            if ($firstTime) {
                $plan->update([
                    'status' => in_array($plan->status, [TreatmentPlanStatus::Draft, TreatmentPlanStatus::Proposed], true) ? TreatmentPlanStatus::Accepted : $plan->status,
                    'accepted_at' => now(),
                    'payment_mode' => $mode,
                ]);

                foreach ($schedule as $row) {
                    $plan->installments()->create($row);
                }

                // En un 2x1, la otra persona queda aceptada con la firma de quien paga.
                $plan->companionPlans()->get()->each(fn (TreatmentPlan $companionPlan) => $this->accept($companionPlan));
            }

            $this->lockItems($plan, $items, $firstTime && $mode->hasSchedule());

            return $plan->signatures()->create([
                'signer_name' => $signer['signer_name'],
                'signer_document' => $signer['signer_document'] ?? null,
                'signer_relationship' => $signer['signer_relationship'],
                'signature_path' => $path,
                'summary' => $this->summary($items, $firstTime ? $mode : PlanPaymentMode::PerSession, $schedule),
                'total' => $total,
                'signed_at' => now(),
                'created_by_user_id' => $by->getKey(),
            ]);
        });

        $this->billing->chargeDueInstallments($plan->patient, $by);

        return $signed;
    }

    /**
     * Cronograma de pagos. Pago único: todo hoy (con el descuento por pagar todo junto, si hay).
     * En cuotas: la inicial hoy y las cuotas en soles enteros, una por mes desde la primera fecha;
     * la última ajusta la diferencia para que el total sea exacto. Si se fijó la cuota mensual,
     * de ella sale cuántas son; si no, el resto se reparte en el número de cuotas.
     *
     * @param  array{down_payment?: float|string|null, installments?: int|string|null, monthly_amount?: float|string|null, first_due_on?: mixed, upfront_discount?: float|string|null}  $terms
     * @return list<array{number: int, due_on: CarbonImmutable, amount: float, discount: float}>
     */
    public static function schedule(float $total, PlanPaymentMode $mode, array $terms, CarbonInterface $today): array
    {
        $today = CarbonImmutable::instance($today)->startOfDay();

        if ($mode === PlanPaymentMode::Upfront) {
            $discount = round(min($total, max(0, (float) ($terms['upfront_discount'] ?? 0))), 2);

            return [['number' => 0, 'due_on' => $today, 'amount' => $total, 'discount' => $discount]];
        }

        if ($mode !== PlanPaymentMode::Installments) {
            return [];
        }

        $down = round(max(0, (float) ($terms['down_payment'] ?? 0)), 2);
        $monthly = round(max(0, (float) ($terms['monthly_amount'] ?? 0)), 2);
        $first = filled($terms['first_due_on'] ?? null) ? CarbonImmutable::parse($terms['first_due_on'])->startOfDay() : $today->addMonthNoOverflow();
        $rest = round($total - $down, 2);
        $count = $monthly > 0 ? self::installmentsFor($rest, $monthly) : (int) ($terms['installments'] ?? 0);

        if ($count < 1 || $count > self::MAX_INSTALLMENTS || $rest <= 0 || $first->lt($today)) {
            throw ClinicalException::invalidPaymentTerms();
        }

        $each = $monthly > 0 ? min($monthly, $rest) : self::evenInstallment($rest, $count);

        $rows = $down > 0 ? [['number' => 0, 'due_on' => $today, 'amount' => $down, 'discount' => 0.0]] : [];

        foreach (range(1, $count) as $number) {
            $rows[] = [
                'number' => $number,
                'due_on' => $first->addMonthsNoOverflow($number - 1),
                'amount' => $number < $count ? $each : round($rest - $each * ($count - 1), 2),
                'discount' => 0.0,
            ];
        }

        return $rows;
    }

    /** Cuota pareja en soles enteros; con montos chicos, el redondeo no alcanza y se usan céntimos. */
    public static function evenInstallment(float $rest, int $count): float
    {
        $each = round($rest / max(1, $count));

        return $each * ($count - 1) >= $rest || $each <= 0 ? round($rest / max(1, $count), 2) : $each;
    }

    /** Cuántas cuotas hacen falta para pagar el resto con esa cuota mensual (la última es la que falta). */
    public static function installmentsFor(float $rest, float $monthly): int
    {
        return $monthly > 0 && $rest > 0 ? (int) ceil(round($rest / $monthly, 6)) : 0;
    }

    /**
     * El cronograma en una línea, para revisarlo antes de firmar y para el presupuesto. Sin fechas,
     * para las condiciones de un paquete, que se fijan el día que se firma.
     *
     * @param  list<array{number: int, due_on: CarbonInterface, amount: float, discount: float}>  $schedule
     */
    public static function scheduleText(PlanPaymentMode $mode, array $schedule, bool $withDates = true): string
    {
        if ($mode === PlanPaymentMode::PerSession || $schedule === []) {
            return 'Paga lo de cada sesión al terminarla.';
        }

        if ($mode === PlanPaymentMode::Upfront) {
            $row = $schedule[0];

            return 'Pago único de '.Money::format($row['amount'] - $row['discount']).($withDates ? ' el '.$row['due_on']->format('d/m/Y') : '')
                .($row['discount'] > 0 ? ' (con '.Money::format($row['discount']).' de descuento por pagar todo junto)' : '').'.';
        }

        $rows = collect($schedule);
        $down = $rows->firstWhere('number', 0);
        $cuotas = $rows->where('number', '>', 0)->values();
        $first = $cuotas->first();
        $last = $cuotas->last();
        $same = $cuotas->every(fn (array $row) => $row['amount'] == $first['amount']);

        $amounts = match (true) {
            $cuotas->count() === 1 => '1 cuota de '.Money::format($first['amount']),
            $same => $cuotas->count().' cuotas de '.Money::format($first['amount']),
            default => ($cuotas->count() - 1).' cuotas de '.Money::format($first['amount']).' y la última de '.Money::format($last['amount']),
        };

        if (! $withDates) {
            $amounts = match (true) {
                $cuotas->count() === 1 => '1 cuota de '.Money::format($first['amount']),
                $same => $cuotas->count().' cuotas mensuales de '.Money::format($first['amount']),
                default => $cuotas->count().' cuotas mensuales: '.($cuotas->count() - 1).' de '.Money::format($first['amount']).' y la última de '.Money::format($last['amount']),
            };

            return ($down ? 'Inicial de '.Money::format($down['amount']).' y ' : '').$amounts.'.';
        }

        return collect([
            $down ? 'Inicial de '.Money::format($down['amount']).' el '.$down['due_on']->format('d/m/Y') : null,
            $amounts.($cuotas->count() > 1 ? ', del '.$first['due_on']->format('d/m/Y').' al '.$last['due_on']->format('d/m/Y') : ', el '.$first['due_on']->format('d/m/Y')),
        ])->filter()->implode(' y ').'.';
    }

    /** La forma de pago acordada en una línea: "Por sesión: …", "Pago único de S/ 899.00 el …". */
    public function paymentText(TreatmentPlan $plan): string
    {
        $mode = $plan->payment_mode ?? PlanPaymentMode::PerSession;
        $schedule = $plan->installments
            ->map(fn (TreatmentPlanInstallment $installment) => ['number' => $installment->number, 'due_on' => $installment->due_on, 'amount' => (float) $installment->amount, 'discount' => (float) $installment->discount])
            ->all();

        return $mode->getLabel().'. '.self::scheduleText($mode, $schedule);
    }

    /**
     * Lo que se hizo del plan (según su precio acordado) y lo que ya se cargó por su cronograma:
     * si el paciente lo deja, la diferencia es lo que falta cobrar.
     *
     * @return array{done: float, charged: float}
     */
    public function scheduleProgress(TreatmentPlan $plan): array
    {
        $plan->loadMissing('items', 'installments.charge');

        $done = $plan->items
            ->filter(fn (TreatmentPlanItem $item) => $item->in_payment_schedule)
            ->sum(fn (TreatmentPlanItem $item) => $item->subtotal() * min(1, $item->sessions_done / max(1, $item->sessions_planned)));

        $charged = $plan->installments
            ->filter(fn (TreatmentPlanInstallment $installment) => $installment->charge && $installment->charge->voided_at === null)
            ->sum(fn (TreatmentPlanInstallment $installment) => $installment->charge->net());

        return ['done' => round($done, 2), 'charged' => round($charged, 2)];
    }

    /** El plan no se borra: queda cancelado con lo que se llegó a hacer. */
    public function cancel(TreatmentPlan $plan, ?string $reason = null, ?User $chargeMissingBy = null): void
    {
        DB::transaction(function () use ($plan, $reason, $chargeMissingBy) {
            // Las cuotas que faltaban ya no se cargan. Si se pidió, se carga lo hecho que no alcanzaron a cubrir.
            $progress = $plan->hasSchedule() ? $this->scheduleProgress($plan) : null;

            if ($chargeMissingBy && $progress && $progress['done'] > $progress['charged']) {
                $this->billing->addCharge($plan->patient, [
                    'description' => ($plan->title ?: 'Plan de tratamiento').' · lo hecho que faltaba cobrar',
                    'amount' => round($progress['done'] - $progress['charged'], 2),
                    'doctor_id' => $plan->doctor_id,
                ], $chargeMissingBy);
            }

            $plan->update([
                'status' => TreatmentPlanStatus::Cancelled,
                'notes' => trim(collect([$plan->notes, $reason ? 'Cancelado: '.$reason : null])->filter()->implode("\n")) ?: null,
            ]);

            // Sin el plan de quien paga, el 2x1 ya no corre para la otra persona.
            $plan->companionPlans()->open()->get()
                ->each(fn (TreatmentPlan $companionPlan) => $this->cancel($companionPlan, 'se canceló el 2x1 de '.$plan->patient->full_name));
        });
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
            // Si ya se está trabajando, el paciente lo aceptó: su precio queda fijo.
            if ($plan->accepted_at === null) {
                $this->lockItems($plan, $items->whereNull('accepted_at'), false);
            }

            $plan->update(['status' => TreatmentPlanStatus::InProgress, 'completed_at' => null, 'accepted_at' => $plan->accepted_at ?? now(), 'payment_mode' => $plan->payment_mode ?? PlanPaymentMode::PerSession]);
        } elseif (in_array($plan->status, [TreatmentPlanStatus::InProgress, TreatmentPlanStatus::Completed], true)) {
            $plan->update(['status' => TreatmentPlanStatus::Accepted, 'completed_at' => null]);
        }
    }

    /** @param  iterable<TreatmentPlanItem>  $items */
    private function lockItems(TreatmentPlan $plan, iterable $items, bool $inSchedule): void
    {
        $plan->items()
            ->whereKey(collect($items)->map->getKey()->all())
            ->update(['accepted_at' => now(), 'in_payment_schedule' => $inSchedule]);

        $plan->unsetRelation('items');
    }

    /**
     * Lo que se firmó, tal como estaba.
     *
     * @param  Collection<int, TreatmentPlanItem>  $items
     * @param  list<array{number: int, due_on: CarbonInterface, amount: float, discount: float}>  $schedule
     */
    private function summary(Collection $items, PlanPaymentMode $mode, array $schedule): string
    {
        return $items
            ->map(fn (TreatmentPlanItem $item) => '• '.$item->label().($item->quantity > 1 ? " × {$item->quantity}" : '').': '.Money::format($item->subtotal()))
            ->push('Total: '.Money::format($items->sum(fn (TreatmentPlanItem $item) => $item->subtotal())))
            ->push('Forma de pago: '.$mode->getLabel().'. '.self::scheduleText($mode, $schedule))
            ->implode("\n");
    }

    /** @return list<string> "tratamiento-pieza" de lo que ya está en planes vigentes. */
    public function plannedKeys(int $patientId): array
    {
        return TreatmentPlanItem::query()
            ->whereHas('plan', fn ($query) => $query->open()->where('patient_id', $patientId))
            ->whereNot('status', PlanItemStatus::Cancelled)
            ->get(['treatment_id', 'tooth'])
            ->map(fn (TreatmentPlanItem $item) => $item->treatment_id.'-'.$item->tooth)
            ->all();
    }
}
