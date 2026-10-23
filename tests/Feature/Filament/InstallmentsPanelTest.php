<?php

namespace Tests\Feature\Filament;

use App\Enums\MessageTemplateKey;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Installments\Pages\ManageInstallments;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Billing\BillingService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class InstallmentsPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_reception_sees_the_late_cuotas_and_those_due_this_week_and_writes_to_the_patient(): void
    {
        $this->freezeMonday();
        $doctor = Doctor::factory()->create();
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $paidUp = Patient::factory()->create();

        $plan = $this->planInCuotas($rosa, $doctor, 'Ortodoncia', ['2026-10-02', '2026-11-05', '2026-12-02']);
        $other = $this->planInCuotas($paidUp, $doctor, 'Implante', ['2026-10-02']);
        app(BillingService::class)->chargeDueInstallments();
        Payment::factory()->for($paidUp)->create(['amount' => 250]);

        [$late, $thisWeek, $nextMonth] = $plan->installments;
        $this->actingAs(User::factory()->reception()->create());

        $page = Livewire::test(ManageInstallments::class)
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$thisWeek, $nextMonth, $other->installments->sole()])
            ->assertTableColumnStateSet('due', 'S/ 250.00', $late)
            ->assertTableColumnHasDescription('due_on', 'Hace 31 días', $late)
            ->mountAction(TestAction::make('remind')->table($late))
            ->assertSchemaStateSet(['body' => "Hola Rosa, te escribimos de Clínica Vega Implant.\nTu cuota 1 de 3 de ortodoncia venció el viernes 2 de octubre y queda S/ 250.00 por pagar.\n\nPuedes pagarla en el consultorio con Yape, Plin, tarjeta o efectivo. Si ya la pagaste, avísanos y lo revisamos."])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(MessageTemplateKey::InstallmentOverdue, WhatsappMessage::sole()->template_key);
        $this->assertNotNull($late->fresh()->reminded_at);
        $page->assertTableColumnHasDescription('due_on', 'Hace 31 días · avisado el 02/11', $late);

        $page->set('activeTab', 'week')
            ->assertCanSeeTableRecords([$thisWeek])
            ->assertCanNotSeeTableRecords([$late, $nextMonth])
            ->assertTableColumnHasDescription('due_on', 'En 3 días', $thisWeek);
    }

    /** @param  list<string>  $dueDates */
    private function planInCuotas(Patient $patient, Doctor $doctor, string $title, array $dueDates): TreatmentPlan
    {
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => $title, 'status' => TreatmentPlanStatus::InProgress, 'payment_mode' => PlanPaymentMode::Installments, 'accepted_at' => now()->subMonths(2)]);

        foreach ($dueDates as $i => $dueOn) {
            $plan->installments()->create(['number' => $i + 1, 'due_on' => $dueOn, 'amount' => 250]);
        }

        return $plan->load('installments');
    }
}
