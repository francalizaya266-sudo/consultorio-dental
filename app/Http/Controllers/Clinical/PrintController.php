<?php

namespace App\Http\Controllers\Clinical;

use App\Enums\PlanItemStatus;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Http\Controllers\Controller;
use App\Livewire\OdontogramChart;
use App\Models\Consent;
use App\Models\Odontogram;
use App\Models\OdontogramEntry;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Setting;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\TreatmentPlanSignature;
use App\Models\User;
use App\Services\Clinical\ConsentService;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Phone;
use App\Support\Signatures;
use App\Support\Teeth;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Receta, presupuesto, consentimiento e historia listos para imprimir o guardar en PDF desde el navegador. */
class PrintController extends Controller
{
    public function prescription(Prescription $prescription, PrescriptionService $prescriptions): View
    {
        abort_unless(auth()->user()->can('viewClinical', $prescription->patient), 403);

        $prescription->load('patient.account', 'doctor', 'items', 'consultation.diagnoses.icd10Code');

        return view('print.prescription', [
            'prescription' => $prescription,
            'patient' => $prescription->patient,
            'doctor' => $prescription->doctor,
            'phone' => $prescription->patient->account ? Phone::display($prescription->patient->account->phone) : null,
            // En la receta de un niño va su peso: con él se calculó la dosis.
            'weight' => filled($weight = $prescription->consultation?->vital_signs['weight'] ?? null) ? $weight.' kg' : null,
            // Como en el recetario: "K02.1 Caries de la dentina (pieza 36)".
            'diagnosis' => $prescription->consultation?->diagnoses
                ->map(fn ($diagnosis) => ($diagnosis->icd10Code ? "{$diagnosis->icd10Code->code} {$diagnosis->icd10Code->description}" : $diagnosis->description).($diagnosis->tooth ? " (pieza {$diagnosis->tooth})" : ''))
                ->implode('; '),
            'slogan' => Setting::get('clinic.slogan'),
            'items' => $prescription->items->map(fn ($item) => ['medication' => $item->medication, 'instructions' => $prescriptions->instructionsOf($item)]),
        ]);
    }

    public function consent(Consent $consent, ConsentService $consents): View
    {
        abort_unless(auth()->user()->can('view', $consent), 403);

        $consent->load('patient', 'doctor');

        return view('print.consent', [
            'consent' => $consent,
            'patient' => $consent->patient,
            'doctor' => $consent->doctor,
            'signature' => $consents->signatureDataUri($consent),
        ]);
    }

    /** Historia clínica completa para imprimir o guardar en PDF. Queda registrado quién la sacó. */
    public function history(Patient $patient, PrescriptionService $prescriptions): View
    {
        abort_unless(auth()->user()->can('viewClinical', $patient), 403);

        $patient->load([
            'account',
            'medicalBackground',
            'latestOdontogram.entries.condition',
            'consultations' => fn ($query) => $query->oldest('attended_at')->with(['doctor', 'diagnoses.icd10Code', 'planItems', 'prescription.items', 'appointment.treatment']),
            'treatmentPlans' => fn ($query) => $query->oldest()->with(['doctor', 'items', 'signatures']),
            'consents' => fn ($query) => $query->oldest('signed_at')->with('doctor'),
            'clinicalFiles' => fn ($query) => $query->oldest('taken_at'),
        ]);

        $patient->auditEvent('printed');

        $odontogram = $patient->latestOdontogram;

        return view('print.history', [
            ...self::drawing($odontogram),
            'patient' => $patient,
            'doctor' => auth()->user()->doctor,
            'findings' => ($odontogram?->entries ?? collect())->sortBy([['tooth', 'asc'], ['id', 'asc']])->values(),
            'prescriptions' => $prescriptions,
        ]);
    }

    /**
     * El plan con su presupuesto. Quien ve la historia lo imprime completo; recepción y administración, que le
     * hacen seguimiento y lo cobran, solo el presupuesto: sin odontograma, pieza, diagnóstico ni notas (Ley 29733).
     */
    public function plan(TreatmentPlan $plan, TreatmentPlanService $plans): View
    {
        /** @var User $user */
        $user = auth()->user();
        $clinical = $user->can('viewClinical', $plan->patient);
        abort_unless($clinical || $user->managesAllAgendas(), 403);

        $plan->load('patient', 'doctor', 'items.package', 'installments', 'signatures', 'includedIn.patient', 'companionPlans.patient');
        $package = $plan->accepted_at ? null : $plan->offeredPackage();
        // El odontograma de la atención donde se armó el plan o, si no hay, el último: lo rojo es lo que se va a tratar.
        $odontogram = $clinical
            ? Odontogram::query()
                ->where('patient_id', $plan->patient_id)
                ->when($plan->consultation_id, fn ($odontograms) => $odontograms->orderByRaw('consultation_id = ? desc', [$plan->consultation_id]))
                ->latest('id')
                ->with('entries.condition')
                ->first()
            : null;

        return view('print.plan', [
            ...self::drawing($odontogram),
            'clinical' => $clinical,
            'diagnoses' => $clinical ? $plan->diagnosesByTooth() : [],
            'validUntil' => $plan->validUntil(),
            'plan' => $plan,
            'patient' => $plan->patient,
            'doctor' => $plan->doctor,
            'items' => $plan->items->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)->values(),
            'paymentText' => $plan->accepted_at ? $plans->paymentText($plan) : null,
            'packages' => $plan->packageSummaries(),
            // Antes de firmar, las formas de pago que ofrece el paquete.
            'packageOptions' => $package ? TreatmentPlanService::packagePaymentOptions($package, $plan->total()) : [],
            // 2x1: a quién más se atiende con este plan, o quién paga el de este paciente.
            'companions' => $plan->companionNames(),
            'payer' => $plan->includedIn?->patient?->full_name,
            'signatures' => $plan->signatures->map(fn (TreatmentPlanSignature $signature) => ['signature' => $signature, 'image' => Signatures::dataUri($signature->signature_path)]),
            // Abrir la hoja no es entregarla: el borrador pasa a "presentado" cuando se dice que se entregó.
            'deliverUrl' => $plan->status === TreatmentPlanStatus::Draft && auth()->user()->can('update', $plan)
                ? Filament::getPanel('admin')->route('print.plan.delivered', ['plan' => $plan])
                : null,
            'delivered' => (bool) session('planDelivered'),
        ]);
    }

    /**
     * Lo que necesita el dibujo del odontograma (x-odontogram-drawing), con sus hallazgos ya cargados.
     *
     * @return array{odontogram: Odontogram|null, rows: array<int, list<int>>, teeth: array<int, array<string, mixed>>, spans: Collection<int, OdontogramEntry>}
     */
    private static function drawing(?Odontogram $odontogram): array
    {
        $entries = $odontogram?->entries ?? collect();

        return [
            'odontogram' => $odontogram,
            'rows' => $odontogram ? Teeth::rows($odontogram->dentition) : [],
            'teeth' => OdontogramChart::teethData($entries),
            'spans' => $entries->filter(fn (OdontogramEntry $entry) => $entry->to_tooth !== null)->values(),
        ];
    }

    /** Se le entregó el presupuesto al paciente: el borrador pasa a "presentado al paciente". */
    public function planDelivered(TreatmentPlan $plan, TreatmentPlanService $plans): RedirectResponse
    {
        abort_unless(auth()->user()->can('update', $plan), 403);

        $plans->markProposed($plan);

        return redirect()->to(TreatmentPlansRelationManager::printUrl($plan))->with('planDelivered', true);
    }
}
