<?php

namespace App\Http\Controllers\Clinical;

use App\Enums\PlanItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use Illuminate\View\View;

/** Receta y presupuesto listos para imprimir o guardar en PDF desde el navegador. */
class PrintController extends Controller
{
    public function prescription(Prescription $prescription, PrescriptionService $prescriptions): View
    {
        abort_unless(auth()->user()->can('viewClinical', $prescription->patient), 403);

        $prescription->load('patient', 'doctor', 'items');

        return view('print.prescription', [
            'prescription' => $prescription,
            'patient' => $prescription->patient,
            'doctor' => $prescription->doctor,
            'items' => $prescription->items->map(fn ($item) => ['medication' => $item->medication, 'instructions' => $prescriptions->instructionsOf($item)]),
        ]);
    }

    public function plan(TreatmentPlan $plan, TreatmentPlanService $plans): View
    {
        abort_unless(auth()->user()->can('viewClinical', $plan->patient), 403);

        // Imprimirlo es entregárselo al paciente: el borrador pasa a "presentado".
        if (auth()->user()->can('update', $plan)) {
            $plans->markProposed($plan);
        }

        $plan->load('patient', 'doctor', 'items');

        return view('print.plan', [
            'plan' => $plan,
            'patient' => $plan->patient,
            'doctor' => $plan->doctor,
            'items' => $plan->items->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)->values(),
        ]);
    }
}
