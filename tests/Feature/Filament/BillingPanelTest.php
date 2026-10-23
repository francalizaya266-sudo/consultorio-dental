<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\ChargesRelationManager;
use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Billing\BillingService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class BillingPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    /** Una limpieza de hoy ya atendida, con su cargo armado por el sistema. */
    private function completedCleaning(): Appointment
    {
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $doctor = $this->doctorOffering($cleaning);
        $appointment = Appointment::factory()->for($doctor)->for($cleaning)->at('2026-11-02 10:00')->create(['status' => AppointmentStatus::Completed]);
        app(BillingService::class)->chargeAppointment($appointment, User::factory()->reception()->create());

        return $appointment;
    }

    public function test_reception_checks_out_a_completed_appointment_in_one_step(): void
    {
        $this->freezeMonday();
        $appointment = $this->completedCleaning();
        $charge = Charge::sole();
        $this->actingAs($reception = User::factory()->reception()->create());
        Repeater::fake();

        Livewire::test(TodayAppointments::class)
            ->mountAction(TestAction::make('checkout')->table($appointment))
            ->assertSchemaStateSet([
                'lines' => [['id' => $charge->id, 'description' => 'Limpieza dental', 'amount' => 120.0, 'discount' => 0.0]],
                'amount' => 120.0,
                'method' => PaymentMethod::Cash,
            ])
            ->fillForm([
                'lines' => [
                    ['id' => $charge->id, 'description' => 'Limpieza dental', 'amount' => 150, 'discount' => 0],
                    ['id' => null, 'description' => 'Flúor', 'amount' => 30, 'discount' => 0],
                ],
                'amount' => 180,
                'method' => PaymentMethod::Yape->value,
                'reference' => '004512',
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Pago registrado')
            // Pagado todo, el botón ya no aparece.
            ->assertActionHidden(TestAction::make('checkout')->table($appointment));

        $this->assertSame('150.00', $charge->fresh()->amount);
        $payment = Payment::sole();
        $this->assertSame(['180.00', PaymentMethod::Yape, '004512'], [$payment->amount, $payment->method, $payment->reference]);
        $this->assertTrue($payment->receivedBy->is($reception));
        $this->assertSame(0.0, $appointment->patient->balance());
    }

    public function test_a_discount_cannot_be_larger_than_the_price(): void
    {
        $this->freezeMonday();
        $appointment = $this->completedCleaning();
        $this->actingAs(User::factory()->reception()->create());
        Repeater::fake();

        Livewire::test(TodayAppointments::class)
            ->callAction(TestAction::make('checkout')->table($appointment), [
                'lines' => [['id' => Charge::sole()->id, 'description' => 'Limpieza dental', 'amount' => 120, 'discount' => 150]],
                'amount' => 0,
            ])
            ->assertHasFormErrors(['lines.0.discount']);

        $this->assertSame('0.00', Charge::sole()->discount);
    }

    public function test_doctors_do_not_handle_money(): void
    {
        $this->freezeMonday();
        $appointment = $this->completedCleaning();
        $this->actingAs($appointment->doctor->user);

        Livewire::test(TodayAppointments::class)
            ->assertActionHidden(TestAction::make('checkout')->table($appointment));

        Livewire::test(TodayStats::class)->assertDontSee('Cobrado hoy');

        $this->assertFalse(ChargesRelationManager::canViewForRecord($appointment->patient, ViewPatient::class));
    }

    public function test_the_patient_page_shows_the_balance_and_takes_payments_and_manual_charges(): void
    {
        $patient = Patient::factory()->create();
        Charge::factory()->for($patient)->create(['amount' => 200]);
        Payment::factory()->for($patient)->create(['amount' => 80, 'method' => PaymentMethod::Plin]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewPatient::class, ['record' => $patient->getKey()])
            ->assertSee('Debe S/ 120.00')
            ->assertSee('S/ 80.00 · Plin')
            ->mountAction('registerPayment')
            ->assertSchemaStateSet(['amount' => 120.0])
            ->fillForm(['amount' => 100, 'notes' => 'Cuota 1'])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Pago registrado');

        $this->assertSame(20.0, $patient->balance());

        $charges = Livewire::test(ChargesRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewPatient::class])
            ->callAction(TestAction::make('addCharge')->table(), ['description' => 'Trabajo de laboratorio', 'amount' => 90])
            ->assertHasNoFormErrors();

        $lab = Charge::where('description', 'Trabajo de laboratorio')->sole();
        $this->assertSame(110.0, $patient->balance());

        $charges->callAction(TestAction::make('void')->table($lab), ['reason' => 'Se registró en el paciente equivocado'])
            ->assertActionHidden(TestAction::make('void')->table($lab->fresh()));

        $this->assertSame(20.0, $patient->balance());
        $this->assertSame(2, Charge::count());
    }

    public function test_the_patient_list_shows_who_owes(): void
    {
        $owes = Patient::factory()->create();
        $settled = Patient::factory()->create();
        Charge::factory()->for($owes)->create(['amount' => 150]);
        Charge::factory()->for($settled)->create(['amount' => 80]);
        Payment::factory()->for($settled)->create(['amount' => 80]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListPatients::class, ['activeTab' => 'owing'])
            ->assertCanSeeTableRecords([$owes])
            ->assertCanNotSeeTableRecords([$settled])
            ->assertSee('Debe S/ 150.00');

        $this->actingAs($this->doctorOffering(Treatment::factory()->create())->user);

        Livewire::test(ListPatients::class)
            ->assertTableColumnHidden('balance_due')
            ->assertDontSee('Con saldo pendiente');
    }
}
