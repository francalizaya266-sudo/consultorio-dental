<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\ExpenseCategory;
use App\Enums\LabOrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Resources\LabOrders\LabOrderResource;
use App\Filament\Resources\LabOrders\Pages\ManageLabOrders;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Widgets\FollowUpsToday;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Clinical\ConsultationService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class LabOrdersTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $crown;

    private Doctor $doctor;

    private Patient $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->crown = Treatment::factory()->create(['name' => 'Corona']);
        $this->doctor = $this->doctorOffering($this->crown);
        $this->rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
    }

    public function test_a_lab_work_is_followed_from_the_day_it_leaves_until_it_is_paid(): void
    {
        $this->actingAs(User::factory()->reception()->create());
        $visit = $this->visit($this->rosa, '2026-11-05 10:00');

        // Al anotarlo, avisa que la cita quedó antes de la entrega.
        Livewire::test(ManageLabOrders::class)
            ->mountAction('create')
            ->fillForm(['patient_id' => $this->rosa->id, 'due_on' => '2026-11-07'])
            ->assertMountedActionModalSee('Tiene cita el jue. 5 nov., antes de la entrega')
            ->fillForm(['description' => 'Corona de zirconio', 'laboratory' => 'DentalArt', 'cost' => 180])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Trabajo anotado');

        $order = LabOrder::sole();
        $this->assertSame([$this->doctor->id, '2026-11-02', LabOrderStatus::Pending, 'Su cita del jue. 5 nov. es antes de la entrega'], [$order->doctor_id, $order->sent_on->toDateString(), $order->status(), $order->alert()]);

        Livewire::withQueryParams(['tab' => 'attention'])
            ->test(ManageLabOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertSee(['Rosa Quispe', 'Corona de zirconio', 'DentalArt', 'Su cita del jue. 5 nov. es antes de la entrega', 'Sin pagar'])
            ->assertActionVisible(TestAction::make('appointment')->table($order))
            ->assertActionHasUrl(TestAction::make('appointment')->table($order), AppointmentResource::getUrl('view', ['record' => $visit]));

        // En Hoy y en la ficha del paciente dice lo mismo.
        Livewire::test(FollowUpsToday::class)
            ->assertSeeInOrder(['Trabajos de laboratorio', 'Corona de zirconio de Rosa Quispe (su cita del jue. 5 nov. es antes de la entrega).']);
        $this->get(PatientResource::getUrl('view', ['record' => $this->rosa]))
            ->assertOk()
            ->assertSee('Laboratorio: Corona de zirconio · su cita del jue. 5 nov. es antes de la entrega');

        // Al agendarle antes de la entrega, la cita lo advierte; desde el día de la entrega, no.
        Livewire::test(CreateAppointment::class)
            ->fillForm(['patient_id' => $this->rosa->id, 'date' => '2026-11-06'])
            ->assertSee('llega del laboratorio el sáb. 7 nov.')
            ->fillForm(['date' => '2026-11-07'])
            ->assertDontSee('llega del laboratorio');

        // Con la cita movida para después de la entrega, ya no hay nada que resolver.
        $visit->update(['starts_at' => '2026-11-09 10:00', 'ends_at' => '2026-11-09 10:30']);
        $this->assertNull($order->fresh()->alert());
        $this->assertSame(0, LabOrder::query()->needingAttention()->count());

        // Pasó la fecha y no llegó.
        $this->travelTo('2026-11-10 09:00');
        $visit->update(['status' => AppointmentStatus::NoShow]);
        $this->assertSame([LabOrderStatus::Late, 'Debía llegar hace 3 días'], [$order->fresh()->status(), $order->fresh()->alert()]);

        // Llega: como no tiene cita, ofrece agendarla.
        Livewire::test(ManageLabOrders::class)
            ->callAction(TestAction::make('receive')->table($order), ['received_on' => '2026-11-10'])
            ->assertHasNoActionErrors()
            ->assertNotified('Trabajo recibido')
            ->assertActionHidden(TestAction::make('receive')->table($order));
        $this->assertSame([LabOrderStatus::Received, 'Ya llegó y no tiene cita para colocarlo'], [$order->fresh()->status(), $order->fresh()->alert()]);

        Livewire::withQueryParams(['tab' => 'attention'])
            ->test(ManageLabOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertActionHasUrl(TestAction::make('book')->table($order), AppointmentResource::getUrl('create', ['patient' => $this->rosa->id, 'doctor' => $this->doctor->id]));

        // Se le paga al laboratorio: queda en los gastos de Caja y ya no se elimina.
        Livewire::test(ManageLabOrders::class)
            ->callAction(TestAction::make('pay')->table($order), ['method' => PaymentMethod::Yape->value, 'spent_at' => '2026-11-10 09:00'])
            ->assertHasNoActionErrors()
            ->assertNotified('Pago anotado en Gastos')
            ->assertActionHidden(TestAction::make('pay')->table($order))
            ->assertActionDisabled(TestAction::make('delete')->table($order));

        $expense = Expense::sole();
        $this->assertSame([ExpenseCategory::Laboratory, 'DentalArt: Corona de zirconio de Rosa Quispe', 180.0, PaymentMethod::Yape], [$expense->category, $expense->description, (float) $expense->amount, $expense->method]);
        $this->assertTrue($order->fresh()->isPaid());

        // Con la cita para colocarlo, sale de lo pendiente.
        $this->visit($this->rosa, '2026-11-12 10:00');
        $this->assertNull($order->fresh()->alert());
        $this->assertSame(0, LabOrder::query()->needingAttention()->count());
    }

    public function test_only_what_needs_something_today_is_listed_to_resolve(): void
    {
        $lateOrder = LabOrder::factory()->for($this->rosa)->create(['sent_on' => '2026-10-20', 'due_on' => '2026-10-30']);
        $today = LabOrder::factory()->create(['sent_on' => '2026-10-26', 'due_on' => '2026-11-02']);
        // Llega el sábado y su paciente no tiene cita antes.
        $onTime = LabOrder::factory()->create(['due_on' => '2026-11-07']);
        // Llegó ayer y el paciente ya tiene cita.
        $placed = LabOrder::factory()->create(['sent_on' => '2026-10-20', 'due_on' => '2026-11-01', 'received_on' => '2026-11-01']);
        $this->visit($placed->patient, '2026-11-03 10:00');
        // Llegó hace tres meses: ya no se persigue.
        $old = LabOrder::factory()->create(['sent_on' => '2026-07-01', 'due_on' => '2026-07-10', 'received_on' => '2026-07-10']);

        $this->assertEqualsCanonicalizing([$lateOrder->id, $today->id], LabOrder::query()->needingAttention()->pluck('id')->all());
        $this->assertSame(['Debía llegar hace 3 días', 'Llega hoy: confirma que lo trajeron', null, null, null], collect([$lateOrder, $today, $onTime, $placed, $old])->map(fn (LabOrder $order) => $order->fresh()->alert())->all());

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageLabOrders::class)
            ->assertCanSeeTableRecords([$lateOrder, $today, $onTime])
            ->assertCanNotSeeTableRecords([$placed, $old]);
        $this->assertSame('2', LabOrderResource::getNavigationBadge());
    }

    public function test_a_doctor_follows_only_the_works_they_sent(): void
    {
        $mine = LabOrder::factory()->for($this->rosa)->for($this->doctor)->create();
        $other = LabOrder::factory()->for(Doctor::factory())->create();

        $this->actingAs($this->doctor->user);

        Livewire::test(ManageLabOrders::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden(TestAction::make('pay')->table($mine))
            ->callAction(TestAction::make('receive')->table($mine), ['received_on' => '2026-11-02'])
            ->assertNotified('Trabajo recibido');
        $this->assertSame(LabOrderStatus::Received, $mine->fresh()->status());
        $this->assertFalse($this->doctor->user->can('update', $other));
    }

    public function test_the_doctor_sends_a_work_to_the_lab_without_leaving_the_visit(): void
    {
        $this->actingAs($this->doctor->user);
        $consultation = app(ConsultationService::class)->startFromAppointment($this->visit($this->rosa, '2026-11-02 10:00'), $this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->callAction('sendToLab', ['description' => 'Corona de zirconio', 'laboratory' => 'DentalArt', 'due_on' => '2026-11-09', 'cost' => 180])
            ->assertHasNoActionErrors()
            ->assertNotified('Trabajo anotado');

        $order = LabOrder::sole();
        $this->assertSame([$this->rosa->id, $this->doctor->id, $this->doctor->user->id, '2026-11-02', '2026-11-09'], [$order->patient_id, $order->doctor_id, $order->created_by_user_id, $order->sent_on->toDateString(), $order->due_on->toDateString()]);

        // Terminada la atención, también se anota desde ahí.
        $consultation->update(['closed_at' => now()]);
        Livewire::test(ViewConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertActionVisible('sendToLab');
    }

    public function test_a_late_work_says_when_the_patient_comes_and_a_paid_one_keeps_its_cost(): void
    {
        $order = LabOrder::factory()->for($this->rosa)->for($this->doctor)->create(['sent_on' => '2026-10-20', 'due_on' => '2026-10-30', 'cost' => 150]);
        $this->visit($this->rosa, '2026-11-03 10:00');

        // Atrasado y con el paciente citado: se dice la cita, que es lo que apura.
        $this->assertSame('Debía llegar hace 3 días y tiene cita el mar. 3 nov.', $order->fresh()->alert());

        $this->actingAs(User::factory()->reception()->create());

        // No pudo llegar antes de salir; y una vez pagado, su costo es el del gasto.
        Livewire::test(ManageLabOrders::class)
            ->callAction(TestAction::make('receive')->table($order), ['received_on' => '2026-10-10'])
            ->assertHasActionErrors(['received_on'])
            ->unmountAction()
            ->callAction(TestAction::make('pay')->table($order), ['amount' => 150, 'method' => PaymentMethod::Cash->value, 'spent_at' => now()])
            ->assertHasNoActionErrors()
            ->mountAction(TestAction::make('edit')->table($order))
            ->assertFormFieldDisabled('cost');
    }

    private function visit(Patient $patient, string $when): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->crown)->for($patient)->at($when)->confirmed()->create();
    }
}
