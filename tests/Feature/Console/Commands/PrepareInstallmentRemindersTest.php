<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\MessageTemplateKey;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentPlan;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PrepareInstallmentRemindersTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_prepares_one_notice_for_each_cuota_due_in_the_next_days(): void
    {
        $this->freezeMonday();
        $doctor = Doctor::factory()->create();
        $rosa = Patient::factory()->create(['first_name' => 'Rosa']);
        $withCredit = Patient::factory()->create();
        Payment::factory()->for($withCredit)->create(['amount' => 300]);

        $plan = $this->planInCuotas($rosa, $doctor, 'Ortodoncia', ['2026-11-04', '2026-12-04']);
        $this->planInCuotas($withCredit, $doctor, 'Implante', ['2026-11-05']);
        $this->planInCuotas($rosa, $doctor, 'Carillas', ['2026-11-03'], TreatmentPlanStatus::Cancelled);

        $this->artisan('cuotas:preparar-avisos')->expectsOutput('Avisos de cuota preparados: 1')->assertSuccessful();
        $this->artisan('cuotas:preparar-avisos')->expectsOutput('Avisos de cuota preparados: 0')->assertSuccessful();

        $notice = WhatsappMessage::sole();
        $this->assertSame([MessageTemplateKey::InstallmentReminder, $rosa->id], [$notice->template_key, $notice->patient_id]);
        $this->assertStringContainsString('El miércoles 4 de noviembre vence tu cuota 1 de 2 de ortodoncia: S/ 250.00.', app(WhatsAppOutbox::class)->render($notice));
        $this->assertNotNull($plan->installments()->where('number', 1)->sole()->reminded_at);
        $this->assertNull($plan->installments()->where('number', 2)->sole()->reminded_at);
    }

    /** @param  list<string>  $dueDates */
    private function planInCuotas(Patient $patient, Doctor $doctor, string $title, array $dueDates, TreatmentPlanStatus $status = TreatmentPlanStatus::InProgress): TreatmentPlan
    {
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => $title, 'status' => $status, 'payment_mode' => PlanPaymentMode::Installments, 'accepted_at' => now()->subMonth()]);

        foreach ($dueDates as $i => $dueOn) {
            $plan->installments()->create(['number' => $i + 1, 'due_on' => $dueOn, 'amount' => 250]);
        }

        return $plan;
    }
}
