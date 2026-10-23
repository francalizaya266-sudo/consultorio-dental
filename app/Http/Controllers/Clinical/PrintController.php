<?php

namespace App\Http\Controllers\Clinical;

use App\Enums\PlanItemStatus;
use App\Http\Controllers\Controller;
use App\Livewire\OdontogramChart;
use App\Models\Consent;
use App\Models\OdontogramEntry;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Setting;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\TreatmentPlanSignature;
use App\Services\Clinical\ConsentService;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Phone;
use App\Support\Signatures;
use App\Support\Teeth;
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
        $entries = $odontogram?->entries ?? collect();

        return view('print.history', [
            'patient' => $patient,
            'doctor' => auth()->user()->doctor,
            'odontogram' => $odontogram,
            'rows' => $odontogram ? Teeth::rows($odontogram->dentition) : [],
            'teeth' => OdontogramChart::teethData($entries),
            'spans' => $entries->filter(fn (OdontogramEntry $entry) => $entry->to_tooth !== null)->values(),
            'findings' => $entries->sortBy([['tooth', 'asc'], ['id', 'asc']])->values(),
            'prescriptions' => $prescriptions,
        ]);
    }

    public function plan(TreatmentPlan $plan, TreatmentPlanService $plans): View
    {
        abort_unless(auth()->user()->can('viewClinical', $plan->patient), 403);

        // Imprimirlo es entregárselo al paciente: el borrador pasa a "presentado".
        if (auth()->user()->can('update', $plan)) {
            $plans->markProposed($plan);
        }

        $plan->load('patient', 'doctor', 'items.package', 'installments', 'signatures');
        $package = $plan->accepted_at ? null : $plan->offeredPackage();

        return view('print.plan', [
            'plan' => $plan,
            'patient' => $plan->patient,
            'doctor' => $plan->doctor,
            'items' => $plan->items->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)->values(),
            'paymentText' => $plan->accepted_at ? $plans->paymentText($plan) : null,
            'packages' => $plan->packageSummaries(),
            // Antes de firmar, las formas de pago que ofrece el paquete.
            'packageOptions' => $package ? TreatmentPlanService::packagePaymentOptions($package) : [],
            'signatures' => $plan->signatures->map(fn (TreatmentPlanSignature $signature) => ['signature' => $signature, 'image' => Signatures::dataUri($signature->signature_path)]),
        ]);
    }
}
