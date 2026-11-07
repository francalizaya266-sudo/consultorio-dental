<?php

namespace Tests\Feature\Filament;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Widgets\CashClosingCheck;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\DailyClosing;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CashClosing;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DailyClosingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $reception;

    private Patient $rosa;

    private Patient $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-11-02 19:30'));
        $this->reception = User::factory()->reception()->create(['name' => 'Carla Ríos']);

        // Ana pagó todo lo de hoy.
        $ana = Patient::factory()->create(['first_name' => 'Ana', 'last_name' => 'Flores']);
        $this->charge($ana, 'Limpieza dental', 200);
        $this->pay($ana, 200, PaymentMethod::Cash);
        // Rosa se fue debiendo la curación.
        $this->rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Huamán']);
        $this->charge($this->rosa, 'Curación con resina', 120);
        // Luis arrastra una deuda vieja: al día solo le toca lo que se le cargó hoy.
        $this->luis = Patient::factory()->create(['first_name' => 'Luis', 'last_name' => 'Soto']);
        Charge::factory()->for($this->luis)->create(['description' => 'Corona', 'amount' => 500, 'charged_at' => '2026-10-20 10:00']);
        $this->charge($this->luis, 'Radiografía', 80);
    }

    public function test_the_day_says_what_was_due_who_left_owing_and_which_visits_were_left_open(): void
    {
        Appointment::factory()->for($this->rosa)->at('2026-11-02 17:00')->confirmed()->create();
        Appointment::factory()->for($this->rosa)->at('2026-11-02 21:00')->confirmed()->create(); // Todavía no llega.
        $closing = new CashClosing(today()->toImmutable());

        $this->assertSame([['Rosa Huamán', 120.0, 'Curación con resina'], ['Luis Soto', 80.0, 'Radiografía']], $closing->pending()->map(fn (array $line) => [$line['patient']->full_name, $line['due'], $line['concepts']])->all());
        $this->assertSame([200.0, 200.0, 400.0], [$closing->total(), $closing->pendingTotal(), $closing->expected()]);
        $this->assertCount(1, $closing->unclosedAppointments());

        $this->actingAs($this->reception);

        Livewire::test(CashClosingCheck::class, ['pageFilters' => ['date' => '2026-11-02']])
            ->assertSeeInOrder(['Para cobrar', 'S/ 400.00', 'Cobrado', 'S/ 200.00', 'Falta', 'S/ 200.00'])
            ->assertSeeInOrder(['Se fueron debiendo', 'Rosa Huamán', 'S/ 120.00', 'Luis Soto', 'S/ 80.00'])
            ->assertSee(['Citas sin cerrar', '17:00 · Rosa Huamán', 'Falta el arqueo'])
            // Cobrarle a Rosa desde el mismo cuadre.
            ->mountAction(TestAction::make('collect')->arguments(['patient' => $this->rosa->id]))
            ->assertActionDataSet(['amount' => 120.0])
            ->setActionData(['method' => PaymentMethod::Yape->value])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified('Pago registrado')
            ->assertDontSee('Curación con resina')
            ->assertSee(['S/ 320.00', 'S/ 80.00']);

        $this->assertSame(0.0, $this->rosa->balance());
    }

    public function test_closing_the_day_counts_the_cash_and_locks_its_payments_for_reception(): void
    {
        $payment = Payment::sole();
        $this->actingAs($this->reception);

        Livewire::test(CashClosingCheck::class, ['pageFilters' => ['date' => '2026-11-02']])
            ->mountAction('closeDay')
            ->setActionData(['opening_cash' => 100, 'counted_cash' => 280])
            ->callMountedAction()
            ->assertHasActionErrors(['notes' => 'required'])
            ->setActionData(['notes' => 'Se dio vuelto de más'])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertNotified('Día cerrado')
            ->assertSee(['Cerrado por Carla Ríos', 'debía haber S/ 300.00', 'se contó S/ 280.00', 'Faltan S/ 20.00 en la caja.', 'Se dio vuelto de más'])
            ->assertActionHidden('closeDay')
            ->assertActionHidden('reopenDay');

        $record = DailyClosing::sole();
        $this->assertSame(['2026-11-02', 100.0, 300.0, 280.0, 200.0, 200.0, -20.0], [$record->date->toDateString(), (float) $record->opening_cash, (float) $record->expected_cash, (float) $record->counted_cash, (float) $record->collected, (float) $record->pending, $record->difference()]);

        // La hoja impresa lleva el arqueo hecho y quién se fue debiendo.
        $this->get(Filament::getPanel('admin')->route('cash-closing.print', ['date' => '2026-11-02']))
            ->assertOk()
            ->assertSee(['Se fueron debiendo', 'Rosa Huamán', 'Curación con resina', 'Debía haber', 'S/ 300.00', 'S/ 280.00', 'Faltan S/ 20.00 en la caja.', 'Cerró Carla Ríos']);

        // Recepción ya no anula un pago de ese día ni registra uno con esa fecha.
        $this->assertFalse($this->reception->can('update', $payment));
        Livewire::test(ManagePayments::class)
            ->assertActionDisabled(TestAction::make('void')->table($payment))
            ->mountAction('registerPayment')
            ->setActionData(['patient_id' => $this->rosa->id, 'amount' => 50, 'paid_at' => '2026-11-02 18:00'])
            ->callMountedAction()
            ->assertHasActionErrors(['paid_at']);

        // La administradora sí: anula y, si hace falta, reabre el día.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->assertTrue($admin->can('update', $payment));

        Livewire::test(CashClosingCheck::class, ['pageFilters' => ['date' => '2026-11-02']])
            ->callAction('reopenDay')
            ->assertNotified('Día reabierto')
            ->assertSee('Falta el arqueo');

        $this->assertNull(DailyClosing::first());
        $this->assertTrue($this->reception->can('update', $payment));
    }

    public function test_a_closing_that_adds_up_needs_no_notes_and_suggests_the_last_float(): void
    {
        DailyClosing::factory()->create(['date' => '2026-10-31', 'opening_cash' => 150]);
        $this->actingAs($this->reception);

        Livewire::test(CashClosingCheck::class, ['pageFilters' => ['date' => '2026-11-02']])
            ->mountAction('closeDay')
            ->assertActionDataSet(['opening_cash' => 150.0])
            ->setActionData(['counted_cash' => 350])
            ->callMountedAction()
            ->assertHasNoErrors()
            ->assertSee('Cuadra: el efectivo contado es el que debía haber.');

        $this->assertSame(0.0, DailyClosing::query()->whereDate('date', '2026-11-02')->sole()->difference());
    }

    public function test_one_persons_view_does_not_close_the_day(): void
    {
        $this->actingAs($this->reception);

        Livewire::test(CashClosingCheck::class, ['pageFilters' => ['date' => '2026-11-02', 'person' => $this->reception->id]])
            ->assertSee('El cuadre y el arqueo se hacen con el día completo')
            ->assertDontSee('Se fueron debiendo')
            ->assertActionHidden('closeDay');
    }

    public function test_money_in_favour_is_given_back_as_its_own_movement(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'Elena', 'last_name' => 'Quispe']);
        $this->charge($patient, 'Blanqueamiento', 300);
        $payment = $this->pay($patient, 500, PaymentMethod::Card);
        $this->actingAs($this->reception);

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewPatient::class])
            ->assertCanSeeTableRecords([$payment])
            ->mountAction(TestAction::make('refund')->table())
            ->assertActionDataSet(['amount' => 200.0, 'method' => PaymentMethod::Cash])
            ->setActionData(['amount' => 250])
            ->callMountedAction()
            ->assertHasActionErrors(['amount', 'reason'])
            ->setActionData(['amount' => 200, 'reason' => 'Pagó de más con tarjeta'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Devolución registrada')
            ->assertSee('Devolución: Pagó de más con tarjeta')
            ->assertActionHidden(TestAction::make('refund')->table());

        $refund = Payment::query()->latest('id')->first();
        $this->assertSame([-200.0, PaymentMethod::Cash, $this->reception->id], [(float) $refund->amount, $refund->method, $refund->received_by_user_id]);
        $this->assertSame(0.0, $patient->balance());
        // Sale de la caja del día: el efectivo que debe haber baja.
        $this->assertSame(0.0, (new CashClosing(today()->toImmutable()))->cash());
        // Y no cuenta como su medio de pago habitual.
        $this->assertSame(PaymentMethod::Card, $patient->usualPaymentMethod());
    }

    private function charge(Patient $patient, string $description, int $amount): Charge
    {
        return Charge::factory()->for($patient)->create(['description' => $description, 'amount' => $amount, 'charged_at' => '2026-11-02 10:00']);
    }

    private function pay(Patient $patient, int $amount, PaymentMethod $method): Payment
    {
        return Payment::factory()->for($patient)->create(['amount' => $amount, 'method' => $method, 'paid_at' => '2026-11-02 11:00', 'received_by_user_id' => $this->reception->id]);
    }
}
