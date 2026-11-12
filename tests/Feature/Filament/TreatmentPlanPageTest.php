<?php

namespace Tests\Feature\Filament;

use App\Enums\MessageTemplateKey;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\TreatmentPlans\Pages\ViewTreatmentPlan;
use App\Filament\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/**
 * La página de un presupuesto: lo que incluye, cómo va su pago y su seguimiento. Antes, tocar un presupuesto
 * abría la ficha o la historia del paciente, las mismas pantallas a las que se llega por Pacientes.
 */
class TreatmentPlanPageTest extends TestCase
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

    public function test_reception_reads_a_budget_without_the_tooth_or_its_clinical_title(): void
    {
        $plan = $this->plan($this->patient('Rosa', 'Quispe'), $this->torres, TreatmentPlanStatus::Proposed, [['Corona', '46', 800], ['Endodoncia', '46', 400]], proposedDaysAgo: 9);
        $plan->update(['title' => 'Rehabilitación de la 46']);

        $this->actingAs(User::factory()->reception()->create());

        $this->get(TreatmentPlanResource::getUrl('view', ['record' => $plan]))
            ->assertOk()
            ->assertSee(['Presupuesto de Rosa Quispe', 'S/ 1,200.00 · Hace 9 días sin respuesta'])
            ->assertSeeInOrder(['Lo que incluye', 'Corona', 'S/ 800.00', 'Endodoncia', 'S/ 400.00', 'Total', 'S/ 1,200.00'])
            ->assertSeeInOrder(['Seguimiento', 'Presupuesto presentado', 'Todavía no se le escribe', 'Sin cita: falta agendar lo que sigue', 'Lo armó', 'Dra. Ana Torres'])
            // Recepción ve el procedimiento, no la pieza ni el nombre que le puso la doctora (Ley 29733).
            ->assertDontSee(['pieza 46', 'Rehabilitación de la 46'])
            // Sin aceptar todavía no hay pagos que mostrar.
            ->assertDontSee('Total aceptado');

        Livewire::test(ViewTreatmentPlan::class, ['record' => $plan->getKey()])
            ->assertActionHidden('clinicalRecord')
            ->assertActionHasUrl('patient', PatientResource::getUrl('view', ['record' => $plan->patient_id]));
    }

    public function test_the_doctor_who_treats_the_patient_reads_it_with_the_tooth_and_reaches_the_record(): void
    {
        // Lo armó el Dr. Ramos; la Dra. Torres también atiende a la paciente, así que lo abre como en su historia.
        $plan = $this->plan($rosa = $this->patient('Rosa', 'Quispe'), $this->ramos, TreatmentPlanStatus::Proposed, [['Corona', '46', 800]]);
        $plan->update(['title' => 'Rehabilitación de la 46']);
        Appointment::factory()->for($rosa)->for($this->torres)->at('2026-10-30 10:00')->create();

        $this->actingAs($this->torres->user);

        Livewire::test(ViewTreatmentPlan::class, ['record' => $plan->getKey()])
            ->assertSee(['Corona · pieza 46', 'Rehabilitación de la 46'])
            ->assertActionHasUrl('clinicalRecord', PatientResource::getUrl('clinical', ['record' => $rosa, 'relation' => '1']));
    }

    public function test_a_doctor_cannot_open_the_budget_of_a_patient_they_do_not_treat(): void
    {
        $plan = $this->plan($this->patient('Luis', 'Mamani'), $this->ramos, TreatmentPlanStatus::Proposed, [['Limpieza', null, 120]]);

        $this->assertFalse($this->torres->user->can('viewBudget', $plan));

        $this->actingAs($this->torres->user)
            ->get(TreatmentPlanResource::getUrl('view', ['record' => $plan]))
            ->assertForbidden();
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_the_login(): void
    {
        $plan = $this->plan($this->patient('Rosa', 'Quispe'), $this->torres, TreatmentPlanStatus::Proposed, [['Corona', '46', 800]]);

        $this->get(TreatmentPlanResource::getUrl('view', ['record' => $plan]))
            ->assertRedirect(filament()->getPanel('admin')->getLoginUrl());
    }

    public function test_an_accepted_plan_shows_how_its_payment_is_going_and_offers_to_collect(): void
    {
        // Ana ya se hizo la corona (S/ 800) y dejó S/ 300; le falta la endodoncia, que todavía no se hace.
        $ana = $this->patient('Ana', 'Flores');
        $plan = $this->plan($ana, $this->torres, TreatmentPlanStatus::InProgress, [['Corona', '46', 800], ['Endodoncia', '46', 400]]);
        Charge::factory()->for($ana)->create(['treatment_plan_item_id' => $plan->items()->first()->id, 'amount' => 800]);
        Payment::factory()->for($ana)->create(['amount' => 300]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewTreatmentPlan::class, ['record' => $plan->getKey()])
            ->assertSee('Plan de Ana Flores')
            ->assertSeeInOrder(['Total aceptado', 'S/ 1,200.00', 'Pagó', 'S/ 300.00', 'Debe', 'S/ 500.00', 'Falta pagar', 'S/ 900.00'])
            ->assertActionVisible('registerPayment');
    }

    public function test_a_plan_paid_in_cuotas_shows_its_schedule_and_what_is_already_late(): void
    {
        // Ortodoncia de S/ 500 en dos cuotas: la de octubre ya venció y nadie la pagó; la de diciembre aún no.
        $rosa = $this->patient('Rosa', 'Quispe');
        $plan = TreatmentPlan::create(['patient_id' => $rosa->id, 'doctor_id' => $this->torres->id, 'status' => TreatmentPlanStatus::InProgress, 'payment_mode' => PlanPaymentMode::Installments, 'accepted_at' => now()->subMonths(2)]);
        $plan->items()->create(['description' => 'Ortodoncia', 'unit_price' => 500, 'accepted_at' => $plan->accepted_at, 'in_payment_schedule' => true]);
        $plan->installments()->create(['number' => 1, 'due_on' => '2026-10-02', 'amount' => 250]);
        $plan->installments()->create(['number' => 2, 'due_on' => '2026-12-02', 'amount' => 250]);

        $this->actingAs(User::factory()->reception()->create());

        // Al abrirlo, la cuota vencida entra al saldo aunque la tarea nocturna no haya pasado.
        Livewire::test(ViewTreatmentPlan::class, ['record' => $plan->getKey()])
            ->assertSeeInOrder(['Total aceptado', 'S/ 500.00', 'Pagó', 'S/ 0.00', 'Debe (atrasado)', 'S/ 250.00', 'Falta pagar', 'S/ 500.00'])
            ->assertSeeInOrder(['02/10/2026', 'Atrasado', '02/12/2026', 'Por vencer'])
            ->assertActionVisible('registerPayment');

        $this->assertSame(250.0, $rosa->fresh()->balance());
    }

    public function test_in_a_two_for_one_each_page_says_who_pays_and_who_else_is_treated(): void
    {
        $paying = $this->plan($this->patient('Rosa', 'Quispe'), $this->torres, TreatmentPlanStatus::Accepted, [['Limpieza', null, 120]]);
        $included = $this->plan($this->patient('Luis', 'Mamani'), $this->torres, TreatmentPlanStatus::Accepted, [['Limpieza', null, 0]]);
        $included->update(['included_in_plan_id' => $paying->id]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewTreatmentPlan::class, ['record' => $paying->getKey()])
            ->assertSee(['También se atiende a Luis Mamani', 'Total aceptado']);

        // El plan de la otra persona no lleva pagos: no hay nada que cobrarle a ella.
        Livewire::test(ViewTreatmentPlan::class, ['record' => $included->getKey()])
            ->assertSee('Sin costo: lo paga Rosa Quispe')
            ->assertDontSee('Total aceptado')
            ->assertActionHidden('registerPayment');
    }

    public function test_collecting_is_not_offered_when_the_patient_owes_nothing(): void
    {
        $plan = $this->plan($this->patient('Ana', 'Flores'), $this->torres, TreatmentPlanStatus::Accepted, [['Corona', '46', 800]]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewTreatmentPlan::class, ['record' => $plan->getKey()])
            ->assertSeeInOrder(['Pagó', 'S/ 0.00', 'Falta pagar', 'S/ 800.00'])
            ->assertDontSee('Debe')
            ->assertActionHidden('registerPayment');
    }

    public function test_the_page_follows_up_with_the_same_message_and_appointment_as_the_list(): void
    {
        $plan = $this->plan($this->patient('Ana', 'Flores'), $this->torres, TreatmentPlanStatus::Accepted, [['Corona', '46', 800]]);
        $item = $plan->items()->first();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewTreatmentPlan::class, ['record' => $plan->getKey()])
            ->assertActionHasUrl('book', AppointmentResource::newUrl(['patient' => $plan->patient_id, 'doctor' => $plan->doctor_id, 'treatment' => $item->treatment_id, 'plan_item' => $item->id]))
            ->mountAction('whatsapp')
            ->assertSchemaStateSet(fn (array $state) => ['body' => str_contains($state['body'], 'queda pendiente: Corona.') ? $state['body'] : 'el aviso del plan sin próxima cita'])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertSee('Le escribiste hace 0 segundos');

        $this->assertSame(MessageTemplateKey::PlanNextSession, WhatsappMessage::sole()->template_key);
    }

    public function test_a_plan_with_its_next_appointment_has_nobody_to_chase(): void
    {
        $plan = $this->plan($ana = $this->patient('Ana', 'Flores'), $this->torres, TreatmentPlanStatus::Accepted, [['Corona', '46', 800]]);
        Appointment::factory()->for($ana)->for($this->torres)->at('2026-11-05 10:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewTreatmentPlan::class, ['record' => $plan->getKey()])
            ->assertSee('Jue. 5 nov., 10:00')
            ->assertDontSee(['Todavía no se le escribe', 'Sin cita'])
            ->assertActionHidden('whatsapp')
            ->assertActionHidden('book');
    }

    public function test_reception_closes_from_the_page_a_budget_the_patient_turned_down(): void
    {
        $plan = $this->plan($this->patient('Rosa', 'Quispe'), $this->torres, TreatmentPlanStatus::Proposed, [['Ortodoncia', null, 450]], proposedDaysAgo: 9);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewTreatmentPlan::class, ['record' => $plan->getKey()])
            ->callAction('decline', ['reason' => 'el precio'])
            ->assertHasNoErrors()
            ->assertSee(['Cancelado', 'el paciente no lo aceptó (el precio)'])
            ->assertActionHidden('decline')
            ->assertActionHidden('whatsapp');

        $this->assertSame(TreatmentPlanStatus::Cancelled, $plan->fresh()->status);
    }

    public function test_the_patient_page_lists_their_budgets_and_each_one_opens_its_own_page(): void
    {
        $rosa = $this->patient('Rosa', 'Quispe');
        $open = $this->plan($rosa, $this->torres, TreatmentPlanStatus::Proposed, [['Corona', '46', 800]], proposedDaysAgo: 9);
        $done = $this->plan($rosa, $this->torres, TreatmentPlanStatus::Completed, [['Limpieza', null, 120]]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewPatient::class, ['record' => $rosa->getKey()])
            // El vigente primero; recepción lee el procedimiento, no la pieza.
            ->assertSeeInOrder(['Presupuestos y planes', 'Corona', 'S/ 800.00', 'Presupuesto presentado', 'Hace 9 días sin respuesta', 'Limpieza', 'S/ 120.00', 'Terminado'])
            ->assertDontSee('pieza 46')
            ->assertSeeHtml('href="'.e(TreatmentPlanResource::getUrl('view', ['record' => $open])).'"')
            ->assertSeeHtml('href="'.e(TreatmentPlanResource::getUrl('view', ['record' => $done])).'"');
    }

    public function test_the_patient_page_has_no_budgets_section_when_nothing_was_proposed(): void
    {
        $luis = $this->patient('Luis', 'Mamani');

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewPatient::class, ['record' => $luis->getKey()])
            ->assertSee('Celular y familia')
            ->assertDontSee('Presupuestos y planes');
    }

    private function patient(string $firstName, string $lastName): Patient
    {
        return Patient::factory()->for(Account::factory())->create(['first_name' => $firstName, 'last_name' => $lastName]);
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
