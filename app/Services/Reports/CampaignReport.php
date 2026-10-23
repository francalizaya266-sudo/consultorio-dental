<?php

namespace App\Services\Reports;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentPlanStatus;
use App\Models\Campaign;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanInstallment;
use App\Models\TreatmentPlanItem;

/** Lo que trajo una campaña: pacientes, lo que se vendió con ella y el descuento que se dio. */
final class CampaignReport
{
    /**
     * Vendido: lo cargado con el descuento de la campaña más el precio de los planes armados con su
     * paquete. Descuento: el de esos cargos, el ahorro del paquete (en un 2x1, también lo que no paga
     * la otra persona) y el descuento por pago único.
     *
     * @return array{booked: int, attended: int, new_patients: int, accepted: int, sold: float, discount: float}
     */
    public function of(Campaign $campaign): array
    {
        $attended = $campaign->appointments()->where('status', AppointmentStatus::Completed);
        $charges = $campaign->charges()->whereNull('voided_at')->get(['amount', 'discount']);
        $plans = $campaign->plans()
            ->whereNotNull('accepted_at')
            ->whereNot('status', TreatmentPlanStatus::Cancelled)
            ->with('items', 'installments')
            ->get();
        $items = $plans->flatMap(fn (TreatmentPlan $plan) => $plan->items->whereNotNull('treatment_package_id'));

        return [
            'booked' => $campaign->appointments()->whereNot('status', AppointmentStatus::Cancelled)->count(),
            'attended' => (clone $attended)->distinct()->count('patient_id'),
            'new_patients' => (clone $attended)->where('is_new_patient', true)->distinct()->count('patient_id'),
            'accepted' => $plans->count(),
            'sold' => round($charges->sum(fn ($charge) => (float) $charge->amount - (float) $charge->discount)
                + $items->sum(fn (TreatmentPlanItem $item) => $item->subtotal())
                - $plans->flatMap->installments->sum(fn (TreatmentPlanInstallment $installment) => (float) $installment->discount), 2),
            'discount' => round($charges->sum(fn ($charge) => (float) $charge->discount)
                + $items->sum(fn (TreatmentPlanItem $item) => (float) $item->discount)
                + $plans->flatMap->installments->sum(fn (TreatmentPlanInstallment $installment) => (float) $installment->discount), 2),
        ];
    }
}
