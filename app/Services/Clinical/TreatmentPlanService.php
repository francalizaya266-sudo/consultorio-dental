<?php

namespace App\Services\Clinical;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Enums\PlanItemStatus;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentKind;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\ClinicalException;
use App\Models\Appointment;
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
use App\Support\Teeth;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
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
    public function itemsFromPackage(TreatmentPackage $package, ?int $controlEveryDays = null): array
    {
        return $this->packageWith($package, collect(), $controlEveryDays)['new'];
    }

    /** Cada cuántos días va a control con este paquete, si trae controles (ortodoncia: cada mes). Null si no trae. */
    public static function controlEvery(TreatmentPackage $package): ?int
    {
        $package->loadMissing('items.treatment');

        return $package->items->first(fn (TreatmentPackageItem $item) => TreatmentKind::isControl($item->treatment))?->treatment->nextSessionDays();
    }

    /**
     * Cuántos controles trae el paquete si va a control cada $days días: los del paquete son a su frecuencia
     * de siempre (18 controles al mes); cada 15 días son el doble. El precio del paquete no cambia.
     */
    public static function controlsFor(TreatmentPackage $package, int $days): int
    {
        $package->loadMissing('items.treatment');

        return (int) $package->items
            ->filter(fn (TreatmentPackageItem $item) => TreatmentKind::isControl($item->treatment))
            ->sum(fn (TreatmentPackageItem $item) => self::scaledToEvery((int) $item->quantity, $item, $days));
    }

    /** Las veces de un control del paquete a otra frecuencia: 18 al mes son 36 cada 15 días. */
    private static function scaledToEvery(int $count, TreatmentPackageItem $item, ?int $days): int
    {
        return $days && TreatmentKind::isControl($item->treatment)
            ? max(1, (int) ceil(round($count * $item->treatment->nextSessionDays() / $days, 6)))
            : $count;
    }

    /**
     * El paquete con lo que el doctor mete en él del borrador (lo del diagnóstico). Lo que el paquete ya trae
     * (su curación, su extracción) toma la pieza del diagnóstico en vez de repetirse sin pieza; lo demás entra
     * al precio del paquete. Ese precio se reparte entre todo, así que el total sigue siendo el del paquete.
     *
     * Con $controlEveryDays, sus controles van a esa frecuencia (según los brackets del paciente): cada 15 días
     * son el doble de controles, al mismo precio del paquete.
     *
     * @param  Collection<int, TreatmentPlanItem>  $included
     * @return array{new: list<array<string, mixed>>, included: array<int, array{treatment_package_id: int, unit_price: float, discount: float}>}
     */
    public function packageWith(TreatmentPackage $package, Collection $included, ?int $controlEveryDays = null): array
    {
        $package->loadMissing('items.treatment');
        $remaining = $package->items->mapWithKeys(fn (TreatmentPackageItem $item) => [$item->getKey() => (int) $item->quantity])->all();
        $lines = [];

        foreach ($included as $planItem) {
            $match = $package->items->first(fn (TreatmentPackageItem $item) => $item->treatment_id !== null
                && $item->treatment_id === $planItem->treatment_id
                && $remaining[$item->getKey()] >= (int) $planItem->quantity);

            if ($match) {
                $remaining[$match->getKey()] -= (int) $planItem->quantity;
            }

            $lines[] = ['planItem' => $planItem, 'quantity' => (int) $planItem->quantity, 'price' => $match ? (float) $match->treatment->price_from : (float) $planItem->unit_price];
        }

        foreach ($package->items as $item) {
            if ($remaining[$item->getKey()] > 0) {
                $lines[] = [
                    'packageItem' => $item,
                    'quantity' => self::scaledToEvery($remaining[$item->getKey()], $item, $controlEveryDays),
                    'sessions' => self::scaledToEvery((int) $item->sessions_planned, $item, $controlEveryDays),
                    'every' => $controlEveryDays && TreatmentKind::isControl($item->treatment) ? $controlEveryDays : null,
                    'price' => (float) $item->treatment->price_from,
                ];
            }
        }

        if ($lines === []) {
            return ['new' => [], 'included' => []];
        }

        $total = (float) $package->price;
        $weights = array_map(fn (array $line) => round($line['quantity'] * $line['price'], 2), $lines);

        // Sin precios en el catálogo, se reparte según la cantidad.
        if (array_sum($weights) <= 0) {
            $weights = array_map(fn (array $line) => (float) $line['quantity'], $lines);
        }

        $shares = array_map(fn (float $weight) => round($total * $weight / array_sum($weights), 2), $weights);
        // Los céntimos del redondeo van al de mayor precio.
        $largest = array_search(max($shares), $shares, true);
        $shares[$largest] = round($total - (array_sum($shares) - $shares[$largest]), 2);

        $result = ['new' => [], 'included' => []];

        foreach ($lines as $index => $line) {
            $share = $shares[$index];
            // Si al paquete le toca más que el catálogo, el precio sube lo justo para cubrir su parte.
            $unitPrice = $share <= round($line['quantity'] * $line['price'], 2)
                ? $line['price']
                : ceil(round($share / $line['quantity'] * 100, 6)) / 100;
            $discount = round($line['quantity'] * $unitPrice - $share, 2);

            if (isset($line['planItem'])) {
                $result['included'][$line['planItem']->getKey()] = ['treatment_package_id' => $package->getKey(), 'unit_price' => $unitPrice, 'discount' => $discount];

                continue;
            }

            $item = $line['packageItem'];
            $result['new'][] = [
                'treatment_id' => $item->treatment_id,
                'treatment_package_id' => $package->getKey(),
                'description' => $item->description ?: $item->treatment->name,
                'tooth' => null,
                'surfaces' => null,
                'quantity' => $line['quantity'],
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'sessions_planned' => $line['sessions'],
                'session_interval_days' => $line['every'],
            ];
        }

        return $result;
    }

    /**
     * Lo del borrador que el paquete ya trae: lo que conviene meter en él para no cobrarlo dos veces
     * (la curación del diagnóstico cuando el paquete incluye una curación).
     *
     * @param  Collection<int, TreatmentPlanItem>  $candidates
     * @return list<int>
     */
    public function coveredByPackage(TreatmentPackage $package, Collection $candidates): array
    {
        $package->loadMissing('items');
        $remaining = $package->items->groupBy('treatment_id')->map(fn (Collection $items) => (int) $items->sum('quantity'))->all();
        $covered = [];

        foreach ($candidates as $item) {
            if ($item->treatment_id !== null && ($remaining[$item->treatment_id] ?? 0) >= (int) $item->quantity) {
                $remaining[$item->treatment_id] -= (int) $item->quantity;
                $covered[] = $item->getKey();
            }
        }

        return $covered;
    }

    /**
     * Lo del borrador del doctor que se puede meter en un paquete: lo pendiente y sin precio aceptado que
     * no es ya de otro paquete.
     *
     * @return Collection<int, TreatmentPlanItem>
     */
    public function includableInPackage(Consultation $consultation): Collection
    {
        return $this->currentPlan($consultation, [TreatmentPlanStatus::Draft])?->items()
            ->whereNull('treatment_package_id')
            ->whereNull('accepted_at')
            ->where('status', PlanItemStatus::Pending)
            ->orderBy('sort_order')
            ->get() ?? collect();
    }

    /**
     * Cómo se puede pagar un paquete, una línea por forma: "Pago único de S/ 4,600.00 (…)."
     *
     * @return list<string>
     */
    public static function packagePaymentOptions(TreatmentPackage $package, ?float $total = null): array
    {
        $price = $total ?? (float) $package->price;
        $terms = $package->terms();
        $options = [];

        // La cuota del paquete vale para su precio; si lo del paquete cambió de precio, el resto se reparte en las cuotas.
        if (round($price, 2) !== round((float) $package->price, 2)) {
            $terms['monthly_amount'] = null;
        }

        if ($package->allows_upfront) {
            $options[] = self::scheduleText(PlanPaymentMode::Upfront, self::schedule($price, PlanPaymentMode::Upfront, $terms, today()), false);
        }

        if ($package->allows_installments) {
            try {
                $options[] = 'En cuotas: '.Str::lcfirst(self::scheduleText(PlanPaymentMode::Installments, self::schedule($price, PlanPaymentMode::Installments, $terms, today()), false));
            } catch (ClinicalException $e) {
                $options[] = 'En cuotas: '.Str::lcfirst($e->getMessage());
            }
        }

        return $options ?: ['Por sesión: paga lo de cada sesión al terminarla.'];
    }

    /**
     * Cómo puede pagar un presupuesto con paquete que todavía no acepta: las formas del paquete, con su inicial
     * y sus cuotas, y lo que no es del paquete, en cada sesión. Vacío si ya lo aceptó o no trae paquete
     * (entonces paga por sesión). Se elige al firmar.
     *
     * @return list<string>
     */
    public function paymentOptions(TreatmentPlan $plan): array
    {
        $package = $plan->accepted_at === null ? $plan->offeredPackage() : null;

        if (! $package) {
            return [];
        }

        $items = $plan->items->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)->values();
        $scheduled = round(self::scheduledItems($plan, $items)->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2);
        $apart = round($plan->total() - $scheduled, 2);
        $options = self::packagePaymentOptions($package, $scheduled);

        return $apart > 0 && ($package->allows_upfront || $package->allows_installments)
            ? [...$options, 'Lo que no es del paquete ('.Money::format($apart).') se paga en cada sesión.']
            : $options;
    }

    /**
     * Lo que entra en el pago único o en las cuotas al firmar. Si el plan se armó con un paquete, solo lo del
     * paquete, que así conserva su inicial y sus cuotas; lo demás se paga en cada sesión. Si no, todo.
     *
     * @param  Collection<int, TreatmentPlanItem>  $items
     * @return Collection<int, TreatmentPlanItem>
     */
    public static function scheduledItems(TreatmentPlan $plan, Collection $items): Collection
    {
        return $plan->offeredPackage()
            ? $items->filter(fn (TreatmentPlanItem $item) => $item->treatment_package_id !== null)->values()
            : $items;
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
                'campaign_id' => $plan->campaign_id,
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
     * Lo del borrador que el doctor mete en el paquete ($includedItemIds) queda dentro de su precio, y sus
     * controles van cada $controlEveryDays días si el doctor eligió otra frecuencia.
     *
     * @param  list<int|string>  $includedItemIds
     * @return array{0: TreatmentPlan|null, 1: TreatmentPlan|null} El plan y el de la otra persona del 2x1.
     */
    public function addPackageToDraft(Consultation $consultation, User $by, TreatmentPackage $package, ?Patient $companion = null, array $includedItemIds = [], ?int $controlEveryDays = null): array
    {
        return DB::transaction(function () use ($consultation, $by, $package, $companion, $includedItemIds, $controlEveryDays) {
            $included = $this->includableInPackage($consultation)->whereIn('id', array_map('intval', $includedItemIds))->values();
            ['new' => $items, 'included' => $prices] = $this->packageWith($package, $included, $controlEveryDays);
            [$plan] = $this->addToDraft($consultation, $by, $items);

            foreach ($included as $item) {
                $item->update($prices[$item->getKey()]);
            }

            $plan ??= $included->first()?->plan;
            $plan?->unsetRelation('items');

            if ($plan && $plan->title === 'Plan de tratamiento' && $plan->items()->whereNull('treatment_package_id')->doesntExist()) {
                $plan->update(['title' => $package->name]);
            }

            if ($plan) {
                $this->linkCampaign($plan);
            }

            // Un 2x1 por plan: si ya tiene a la otra persona, el paquete solo se suma.
            $companionPlan = $plan && $package->for_two && $companion && $plan->companionPlans()->open()->doesntExist()
                ? $this->addCompanion($plan, $package, $companion)
                : null;

            return [$plan, $companionPlan];
        });
    }

    /**
     * Si el paciente vino por una campaña que ofrece uno de los paquetes del plan, el plan queda a cuenta
     * de esa campaña (y el de la otra persona del 2x1 también): así cuenta en sus cupos y en su reporte.
     */
    public function linkCampaign(TreatmentPlan $plan): void
    {
        if ($plan->campaign_id !== null || $plan->included_in_plan_id !== null) {
            return;
        }

        $packageIds = $plan->items()->whereNotNull('treatment_package_id')->distinct()->pluck('treatment_package_id');

        $campaignId = $packageIds->isEmpty() ? null : Appointment::query()
            ->where('patient_id', $plan->patient_id)
            ->whereNot('status', AppointmentStatus::Cancelled)
            ->whereHas('campaign', fn ($campaign) => $campaign->whereIn('treatment_package_id', $packageIds))
            ->latest('starts_at')
            ->value('campaign_id');

        if ($campaignId !== null) {
            $plan->update(['campaign_id' => $campaignId]);
            $plan->companionPlans()->whereNull('campaign_id')->update(['campaign_id' => $campaignId]);
        }
    }

    /**
     * Agrega procedimientos al borrador del plan del doctor (lo crea si no hay uno), desde la atención.
     * Es el camino de los paquetes: llegan con su propia forma de pago, así que van en un borrador aparte.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: TreatmentPlan|null, 1: int} El plan y cuántos procedimientos se agregaron.
     */
    public function addToDraft(Consultation $consultation, User $by, array $items): array
    {
        return $this->addTo($consultation, $by, $items, [TreatmentPlanStatus::Draft]);
    }

    /**
     * Agrega procedimientos al plan que el paciente ya lleva con el doctor: al borrador si hay uno, si no al
     * presupuesto presentado o al plan en curso (lo agregado a un plan aceptado se firma aparte). Solo si no
     * tiene ninguno se arma un borrador nuevo. Así lo que aparece en las citas siguientes queda en su plan.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: TreatmentPlan|null, 1: int} El plan y cuántos procedimientos se agregaron.
     */
    public function addToPlan(Consultation $consultation, User $by, array $items): array
    {
        return $this->addTo($consultation, $by, $items, TreatmentPlan::OPEN_STATUSES);
    }

    /**
     * El plan del paciente con el doctor de la atención al que se suma lo nuevo: el borrador si hay uno, si no
     * el último vigente. El que va incluido en un 2x1 queda como se armó: lo nuevo va en un plan aparte.
     *
     * @param  list<TreatmentPlanStatus>  $statuses
     */
    public function currentPlan(Consultation $consultation, array $statuses = TreatmentPlan::OPEN_STATUSES): ?TreatmentPlan
    {
        return TreatmentPlan::query()
            ->where('patient_id', $consultation->patient_id)
            ->where('doctor_id', $consultation->doctor_id)
            ->whereIn('status', $statuses)
            ->whereNull('included_in_plan_id')
            ->get()
            ->sortByDesc(fn (TreatmentPlan $plan) => [$plan->status === TreatmentPlanStatus::Draft, $plan->getKey()])
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<TreatmentPlanStatus>  $statuses  Los planes del doctor a los que se puede sumar.
     * @return array{0: TreatmentPlan|null, 1: int}
     */
    private function addTo(Consultation $consultation, User $by, array $items, array $statuses): array
    {
        if ($by->doctorId() !== $consultation->doctor_id) {
            throw ClinicalException::notAuthor();
        }

        if ($items === []) {
            return [null, 0];
        }

        return DB::transaction(function () use ($consultation, $items, $statuses) {
            $plan = $this->currentPlan($consultation, $statuses)
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

            $plan->unsetRelation('items');

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

    /**
     * Las formas en que se puede firmar el plan. Uno armado con un paquete que se paga en un solo pago o en cuotas
     * se firma solo con esas: con paquete no se cobra por cita (en ortodoncia se paga por mes, vaya cada 15 días
     * o cada mes).
     *
     * @return list<PlanPaymentMode>
     */
    public static function paymentModes(TreatmentPlan $plan): array
    {
        $package = $plan->accepted_at === null ? $plan->offeredPackage() : null;

        if (! $package || ! ($package->allows_upfront || $package->allows_installments)) {
            return PlanPaymentMode::cases();
        }

        return array_values(array_filter([
            $package->allows_upfront ? PlanPaymentMode::Upfront : null,
            $package->allows_installments ? PlanPaymentMode::Installments : null,
        ]));
    }

    /**
     * Aceptado sin firma (por ejemplo, al agendar una de sus sesiones): se paga por sesión y el precio queda fijo.
     * Un paquete que no se paga por sesión se acepta firmando, para elegir cómo; aquí solo queda presentado.
     */
    public function accept(TreatmentPlan $plan): void
    {
        if (! in_array($plan->status, [TreatmentPlanStatus::Draft, TreatmentPlanStatus::Proposed], true)) {
            return;
        }

        if (! in_array(PlanPaymentMode::PerSession, self::paymentModes($plan), true)) {
            $this->markProposed($plan);

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

        if ($mode && ! in_array($mode, self::paymentModes($plan), true)) {
            throw ClinicalException::packageNotPerSession();
        }
        $total = round($items->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2);
        // Con un paquete, el cronograma es el del paquete; lo demás se paga en cada sesión.
        $scheduled = $mode?->hasSchedule() ? self::scheduledItems($plan, $items) : collect();
        $schedule = $mode?->hasSchedule() ? self::schedule(round($scheduled->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2), $mode, $terms, today()) : [];
        $apart = $items->reject(fn (TreatmentPlanItem $item) => $scheduled->contains(fn (TreatmentPlanItem $inSchedule) => $inSchedule->is($item)))->values();
        $path = Signatures::store($signature, $plan->patient_id);

        $signed = DB::transaction(function () use ($plan, $items, $mode, $schedule, $scheduled, $apart, $total, $signer, $path, $by, $firstTime) {
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

            $this->lockItems($plan, $scheduled, true);
            $this->lockItems($plan, $apart, false);

            return $plan->signatures()->create([
                'signer_name' => $signer['signer_name'],
                'signer_document' => $signer['signer_document'] ?? null,
                'signer_relationship' => $signer['signer_relationship'],
                'signature_path' => $path,
                'summary' => $this->summary($items, $firstTime ? $mode : PlanPaymentMode::PerSession, $schedule, $scheduled->isNotEmpty() ? $apart : collect()),
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

    /**
     * La forma de pago acordada en una línea: "Por sesión: …", "Pago único de S/ 899.00 el …". Con cronograma,
     * dice también lo que queda fuera de él (lo que no es del paquete, lo agregado después) y se paga en cada sesión.
     */
    public function paymentText(TreatmentPlan $plan): string
    {
        $plan->loadMissing('items', 'installments');
        $mode = $plan->payment_mode ?? PlanPaymentMode::PerSession;
        $schedule = $plan->installments
            ->map(fn (TreatmentPlanInstallment $installment) => ['number' => $installment->number, 'due_on' => $installment->due_on, 'amount' => (float) $installment->amount, 'discount' => (float) $installment->discount])
            ->all();
        $apart = $plan->hasSchedule()
            ? round($plan->items
                ->filter(fn (TreatmentPlanItem $item) => $item->accepted_at !== null && ! $item->in_payment_schedule && $item->status !== PlanItemStatus::Cancelled)
                ->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2)
            : 0;

        return $mode->getLabel().'. '.self::scheduleText($mode, $schedule).($apart > 0 ? ' Lo demás ('.Money::format($apart).') se paga en cada sesión.' : '');
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

    /**
     * Texto del presupuesto para WhatsApp: un renglón por procedimiento, en el orden en que se hacen, con la pieza
     * por su nombre, el diagnóstico que lo justifica, las sesiones y el precio. Un paquete va en un renglón con su
     * precio y, debajo, lo que incluye.
     * "1. Curación con resina en el primer molar inferior izquierdo (pieza 36), cara oclusal, por caries de la dentina: S/ 150.00".
     */
    public function text(TreatmentPlan $plan): string
    {
        $diagnoses = $plan->diagnosesByTooth();
        $describe = function (TreatmentPlanItem $item, int $quantity) use ($diagnoses): string {
            $name = Teeth::nameOf($item->tooth);
            $where = match (true) {
                $name !== null => ' en el '.Str::lcfirst($name).' (pieza '.$item->tooth.')'.(($surfaces = Teeth::surfacesText($item->surfaces)) ? ', '.$surfaces : ''),
                filled($item->tooth) => ' · pieza '.$item->tooth,
                default => '',
            };
            $why = $diagnoses[(string) $item->tooth] ?? [];

            return $item->description.($quantity > 1 ? " × {$quantity}" : '').$where
                .($why !== [] ? ', por '.mb_strtolower(Arr::join($why, ', ', ' y ')) : '')
                .($item->sessions_planned > 1 ? " · {$item->sessions_planned} sesiones" : '')
                .($item->session_interval_days ? ', '.TreatmentPlanItem::everyText($item->session_interval_days) : '');
        };

        return TreatmentPlan::lines($plan->items->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)->values())
            ->map(fn (array $line, int $i) => ($i + 1).'. '.($line['package']
                ? $line['label'].': '.Money::format($line['price']).'. Incluye:'."\n".$line['items']
                    ->groupBy(fn (TreatmentPlanItem $item) => $describe($item, 1))
                    ->map(fn (Collection $same) => '   - '.$describe($same->first(), (int) $same->sum('quantity')))
                    ->implode("\n")
                : $describe($line['items']->first(), (int) $line['items']->first()->quantity).': '.Money::format($line['price'])))
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
            'plan' => $this->text($plan).(($options = $this->paymentOptions($plan)) !== [] ? "\n\nFormas de pago:\n".collect($options)->map(fn (string $option) => '• '.$option)->implode("\n") : ''),
            'total' => Money::format($plan->total()),
            'vigencia' => ($until = $plan->validUntil()) ? ' (vale hasta el '.$until->translatedFormat('j \d\e F').')' : '',
        ], $plan->patient_id);
    }

    /**
     * Aviso de una cuota para el celular del paciente (o del titular): que está por vencer o que venció.
     * Null si no tiene celular.
     */
    public function installmentDraft(TreatmentPlanInstallment $installment, MessageTemplateKey $key, float $amount): ?WhatsappMessage
    {
        $installment->loadMissing('plan.patient.account', 'plan.installments');
        $plan = $installment->plan;

        if (! $plan->patient->account) {
            return null;
        }

        return $this->outbox->draftForAccount($plan->patient->account, $key, [
            'nombre' => $plan->patient->first_name,
            'paciente' => $plan->patient->full_name,
            'cuota' => mb_strtolower($installment->label()),
            'plan' => mb_strtolower($plan->title ?: 'Plan de tratamiento'),
            'fecha' => $installment->due_on->translatedFormat('l j \d\e F'),
            'monto' => Money::format($amount),
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

    /**
     * Lo siguiente por agendar de lo que el paciente aceptó: el primer procedimiento pendiente que todavía no tiene cita.
     * Con $evenIfNotAccepted cuenta también el presupuesto que aún no acepta (para ofrecerle su primera sesión).
     */
    public function nextToSchedule(Patient $patient, bool $evenIfNotAccepted = false): ?TreatmentPlanItem
    {
        return TreatmentPlanItem::query()
            ->with('plan')
            ->whereHas('plan', fn ($query) => $query
                ->whereIn('status', $evenIfNotAccepted ? TreatmentPlan::OPEN_STATUSES : TreatmentPlan::ACCEPTED_STATUSES)
                ->where('patient_id', $patient->getKey()))
            ->whereIn('status', TreatmentPlanItem::OPEN_STATUSES)
            ->whereDoesntHave('appointments', fn ($query) => $query->where('starts_at', '>=', now())->whereIn('status', AppointmentStatus::changeable()))
            ->orderBy('treatment_plan_id')
            ->orderBy('sort_order')
            ->first();
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
     * @param  Collection<int, TreatmentPlanItem>  $apart  Lo que, con un paquete en cuotas o pago único, se paga en cada sesión.
     */
    private function summary(Collection $items, PlanPaymentMode $mode, array $schedule, Collection $apart): string
    {
        return TreatmentPlan::lines($items)
            ->map(fn (array $line) => '• '.$line['label'].': '.Money::format($line['price']).($line['contents'] !== [] ? ' (incluye: '.implode(', ', $line['contents']).')' : ''))
            ->push('Total: '.Money::format($items->sum(fn (TreatmentPlanItem $item) => $item->subtotal())))
            ->push('Forma de pago: '.$mode->getLabel().'. '.self::scheduleText($mode, $schedule))
            ->when($apart->isNotEmpty(), fn (Collection $lines) => $lines->push('Aparte, en cada sesión: '.$apart->map(fn (TreatmentPlanItem $item) => $item->label())->implode(', ').' ('.Money::format($apart->sum(fn (TreatmentPlanItem $item) => $item->subtotal())).').'))
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
