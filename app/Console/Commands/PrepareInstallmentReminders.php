<?php

namespace App\Console\Commands;

use App\Enums\MessageTemplateKey;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Models\TreatmentPlanInstallment;
use App\Services\Clinical\TreatmentPlanService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cuotas:preparar-avisos')]
#[Description('Prepara en la bandeja de WhatsApp el aviso de las cuotas que vencen en los próximos días')]
class PrepareInstallmentReminders extends Command
{
    public function handle(TreatmentPlanService $plans): int
    {
        $days = (int) config('consultorio.defaults.reminders.installment_days_before', 3);

        // Las cuotas mensuales (la inicial se paga al firmar) que aún no se cargaron ni se avisaron.
        $installments = TreatmentPlanInstallment::query()
            ->where('number', '>', 0)
            ->whereNull('charge_id')
            ->whereNull('reminded_at')
            ->whereDate('due_on', '>', today())
            ->whereDate('due_on', '<=', today()->addDays($days))
            ->whereHas('plan', fn ($query) => $query
                ->where('payment_mode', PlanPaymentMode::Installments)
                ->whereNot('status', TreatmentPlanStatus::Cancelled))
            ->with('plan.patient.account', 'plan.installments')
            ->orderBy('due_on')
            ->get();

        $prepared = 0;

        foreach ($installments as $installment) {
            // Si lo que tiene a favor ya la cubre, no hace falta avisar.
            $covered = -$installment->plan->patient->balance() >= $installment->net();
            $message = $covered ? null : $plans->installmentDraft($installment, MessageTemplateKey::InstallmentReminder, $installment->net());

            $message?->save();
            $installment->update(['reminded_at' => now()]);
            $prepared += $message ? 1 : 0;
        }

        $this->info("Avisos de cuota preparados: {$prepared}");

        return self::SUCCESS;
    }
}
