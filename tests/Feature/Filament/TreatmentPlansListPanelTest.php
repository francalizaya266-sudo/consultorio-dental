<?php

namespace Tests\Feature\Filament;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\TreatmentPlans\Pages\ListTreatmentPlans;
use App\Filament\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TreatmentPlansListPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Doctor $torres;

    private Doctor $ramos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->torres = Doctor::factory()->withSchedule()->create(['name' => 'Dra. Ana Torres']);
        $this->ramos = Doctor::factory()->withSchedule()->create(['name' => 'Dr. Luis Ramos']);
    }

    public function test_reception_and_doctors_reach_the_list_but_doctors_only_see_their_own(): void
    {
        $mine = $this->plan($this->patient('Rosa', 'Quispe'), $this->torres, TreatmentPlanStatus::Proposed, [['Corona', '46', 800]]);
        $theirs = $this->plan($this->patient('Luis', 'Mamani'), $this->ramos, TreatmentPlanStatus::Proposed, [['Limpieza', null, 120]]);

        $this->actingAs(User::factory()->reception()->create());
        $this->get(TreatmentPlanResource::getUrl('index'))->assertOk()->assertSee('Presupuestos y planes');

        Livewire::test(ListTreatmentPlans::class)
            ->assertCanSeeTableRecords([$mine, $theirs])
            // Recepción ve el procedimiento, no la pieza.
            ->assertSee(['Corona', 'Dra. Ana Torres'])
            ->assertDontSee('pieza 46');

        $this->actingAs($this->torres->user);

        Livewire::test(ListTreatmentPlans::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertSee('Corona · pieza 46');
    }

    public function test_the_tabs_follow_up_the_same_plans_as_the_dashboard(): void
    {
        $treatment = Treatment::factory()->create();
        $waiting = $this->plan($this->patient('Rosa', 'Quispe'), $this->torres, TreatmentPlanStatus::Proposed, [['Ortodoncia', null, 450]], proposedDaysAgo: 9);
        $recent = $this->plan($this->patient('Luis', 'Mamani'), $this->torres, TreatmentPlanStatus::Proposed, [['Limpieza', null, 120]], proposedDaysAgo: 2);
        $unscheduled = $this->plan($this->patient('Ana', 'Flores'), $this->torres, TreatmentPlanStatus::Accepted, [['Corona', '46', 800]]);
        $scheduled = $this->plan($pedro = $this->patient('Pedro', 'Huamán'), $this->torres, TreatmentPlanStatus::InProgress, [['Endodoncia', '36', 600]]);
        Appointment::factory()->for($pedro)->for($this->torres)->for($treatment)->at('2026-11-05 10:00')->create();
        $done = $this->plan($this->patient('Carmen', 'Rojas'), $this->torres, TreatmentPlanStatus::Completed, [['Resina', '11', 150]]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListTreatmentPlans::class)
            ->assertCanSeeTableRecords([$waiting, $recent, $unscheduled, $scheduled])
            ->assertCanNotSeeTableRecords([$done])
            ->assertSee(['Hace 9 días sin respuesta', 'Hace 2 días sin respuesta', 'jue. 5 nov., 10:00', 'Sin cita', 'S/ 1,970.00'])
            ->set('activeTab', 'awaiting')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$recent, $unscheduled, $scheduled])
            ->set('activeTab', 'unscheduled')
            ->assertCanSeeTableRecords([$unscheduled])
            ->assertCanNotSeeTableRecords([$waiting, $scheduled])
            ->set('activeTab', 'completed')
            ->assertCanSeeTableRecords([$done]);
    }

    public function test_whatsapp_follows_up_on_that_plan_and_shows_up_as_written(): void
    {
        $plan = $this->plan($this->patient('Rosa', 'Quispe'), $this->ramos, TreatmentPlanStatus::Proposed, [['Ortodoncia', null, 450]], proposedDaysAgo: 9);
        // Un presupuesto más antiguo con otra doctora: el aviso habla del de esta fila.
        $this->plan($plan->patient, $this->torres, TreatmentPlanStatus::Proposed, [['Limpieza', null, 120]], proposedDaysAgo: 20);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListTreatmentPlans::class)
            ->mountTableAction('whatsapp', $plan)
            ->assertSet('mountedActions.0.data.body', fn (string $body) => str_contains($body, 'tu plan de tratamiento con Dr. Luis Ramos'))
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertSee('Hace 9 días sin respuesta · le escribiste hace 0 segundos');

        $message = WhatsappMessage::sole();
        $this->assertSame(MessageTemplateKey::PlanFollowUp, $message->template_key);
        $this->assertSame(MessageStatus::Sent, $message->status);
    }

    public function test_a_plan_without_its_next_appointment_offers_one_for_its_first_pending_procedure(): void
    {
        $plan = $this->plan($this->patient('Ana', 'Flores'), $this->torres, TreatmentPlanStatus::Accepted, [['Corona', '46', 800]]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListTreatmentPlans::class)
            ->assertTableActionVisible('book', $plan)
            ->assertTableActionHasUrl('book', AppointmentResource::getUrl('create', ['patient' => $plan->patient_id, 'plan_item' => $plan->items()->first()->id]), $plan)
            ->mountTableAction('whatsapp', $plan)
            ->assertSet('mountedActions.0.data.body', fn (string $body) => str_contains($body, 'queda pendiente: Corona.'));
    }

    public function test_reception_closes_a_budget_the_patient_turned_down(): void
    {
        $plan = $this->plan($this->patient('Rosa', 'Quispe'), $this->torres, TreatmentPlanStatus::Proposed, [['Ortodoncia', null, 450]], proposedDaysAgo: 9);
        $accepted = $this->plan($this->patient('Ana', 'Flores'), $this->torres, TreatmentPlanStatus::Accepted, [['Corona', '46', 800]]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListTreatmentPlans::class)
            ->assertTableActionHidden('decline', $accepted)
            ->callTableAction('decline', $plan, ['reason' => 'el precio'])
            ->assertHasNoTableActionErrors()
            ->assertCanNotSeeTableRecords([$plan]);

        $this->assertSame(TreatmentPlanStatus::Cancelled, $plan->fresh()->status);
        $this->assertStringContainsString('Cancelado: el paciente no lo aceptó (el precio)', $plan->fresh()->notes);
    }

    private function patient(string $firstName, string $lastName): Patient
    {
        return Patient::factory()->create(['first_name' => $firstName, 'last_name' => $lastName]);
    }

    /** @param  list<array{0: string, 1: string|null, 2: int}>  $items */
    private function plan(Patient $patient, Doctor $doctor, TreatmentPlanStatus $status, array $items, int $proposedDaysAgo = 1): TreatmentPlan
    {
        $plan = TreatmentPlan::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => $status,
            'accepted_at' => in_array($status, TreatmentPlan::ACCEPTED_STATUSES, true) ? now()->subWeek() : null,
            'completed_at' => $status === TreatmentPlanStatus::Completed ? now()->subDay() : null,
        ]);

        foreach ($items as [$description, $tooth, $price]) {
            $plan->items()->create(['description' => $description, 'tooth' => $tooth, 'unit_price' => $price, 'accepted_at' => $plan->accepted_at]);
        }

        $plan->update(['proposed_at' => now()->subDays($proposedDaysAgo)]);

        return $plan;
    }
}
