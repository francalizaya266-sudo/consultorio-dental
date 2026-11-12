<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageStatus;
use App\Enums\PaymentMethod;
use App\Filament\Pages\CashClosing;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Widgets\CashClosingCheck;
use App\Filament\Widgets\TodayStats;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Billing\PaymentReceipt;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class CollectingAndReceiptsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_a_payment_without_a_visit_counts_for_the_doctor_of_what_it_covers_first(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Endodoncia']);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create();
        Charge::factory()->for($patient)->create(['description' => 'Endodoncia · sesión 1 de 2', 'amount' => 250, 'doctor_id' => $doctor->id, 'treatment_id' => $treatment->id, 'charged_at' => now()->subDays(3)]);
        Charge::factory()->for($patient)->create(['description' => 'Radiografía', 'amount' => 40, 'charged_at' => now()->subDay()]);
        $this->actingAs(User::factory()->reception()->create());

        // Desde "Hoy", el mismo cobro que en Caja y en la ficha.
        Livewire::test(Dashboard::class)
            ->mountAction('registerPayment')
            ->setActionData(['patient_id' => $patient->id])
            ->assertActionDataSet(['amount' => 290.0])
            ->setActionData(['amount' => 200, 'method' => PaymentMethod::Yape->value, 'reference' => '0081'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Pago registrado');

        $payment = Payment::sole();
        $this->assertSame([$doctor->id, $treatment->id], [$payment->doctor_id, $payment->treatment_id]);
        $this->assertSame(90.0, $patient->balance());
    }

    public function test_collecting_offers_first_who_was_charged_today_and_still_owes(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $seen = fn (Patient $patient, string $at) => Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at($at)->create(['status' => AppointmentStatus::Completed]);
        $owes = Patient::factory()->create(['first_name' => 'Olivia', 'last_name' => 'Arenas']);
        $seen($owes, '2026-11-02 08:00');
        Charge::factory()->for($owes)->create(['amount' => 120]);
        // Una urgencia sin cita también se cobra hoy: sale igual, después de quien más debe.
        $urgency = Patient::factory()->create(['first_name' => 'Luis', 'last_name' => 'Mamani']);
        Charge::factory()->for($urgency)->create(['amount' => 90]);
        // Quien se atendió hoy y ya pagó, y quien debe de otro día, no salen: a esos se les busca por nombre.
        $paid = Patient::factory()->create(['first_name' => 'Pedro', 'last_name' => 'Huamán']);
        $seen($paid, '2026-11-02 07:00');
        Charge::factory()->for($paid)->create(['amount' => 80]);
        Payment::factory()->for($paid)->create(['amount' => 80]);
        $earlier = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $seen($earlier, '2026-10-30 10:00');
        Charge::factory()->for($earlier)->create(['amount' => 60, 'charged_at' => '2026-10-30 10:40']);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Dashboard::class)
            ->mountAction('registerPayment')
            ->assertFormFieldExists('patient_id', 'mountedActionSchema0', fn (Select $field) => $field->getOptions() === [
                $owes->id => 'Olivia Arenas · debe S/ 120.00',
                $urgency->id => 'Luis Mamani · debe S/ 90.00',
            ])
            // A los demás se les sigue encontrando al escribir.
            ->assertFormFieldExists('patient_id', 'mountedActionSchema0', fn (Select $field) => array_keys($field->getSearchResults('Quispe')) === [$earlier->id])
            ->setActionData(['patient_id' => $owes->id])
            ->assertActionDataSet(['amount' => 120.0]);

        // Son los mismos que cuenta "Cobrado hoy" y lista el cierre del día, a donde lleva ese número.
        Livewire::test(TodayStats::class)
            ->assertSee('Falta cobrar S/ 210.00 a 2 pacientes')
            ->assertSeeHtml('href="'.e(CashClosing::getUrl()).'"');
        Livewire::test(CashClosingCheck::class)
            ->assertSeeInOrder(['Se fueron debiendo', 'Olivia Arenas', 'S/ 120.00', 'Luis Mamani', 'S/ 90.00'])
            ->assertDontSee(['Pedro Huamán', 'Rosa Quispe']);
    }

    public function test_the_receipt_says_what_was_paid_and_what_is_left(): void
    {
        $this->freezeMonday();
        $account = Account::factory()->create(['phone' => '+51987654321']);
        $patient = Patient::factory()->for($account)->create(['first_name' => 'Rosa', 'last_name' => 'Huamán', 'document_number' => '07654321']);
        Charge::factory()->for($patient)->create(['description' => 'Limpieza dental', 'amount' => 120, 'charged_at' => now()->subDays(10)]);
        Charge::factory()->for($patient)->create(['description' => 'Curación con resina', 'amount' => 150, 'charged_at' => now()->subDays(2)]);
        Payment::factory()->for($patient)->create(['amount' => 100, 'method' => PaymentMethod::Cash, 'paid_at' => now()->subDays(5)]);
        $payment = Payment::factory()->for($patient)->create(['amount' => 200, 'method' => PaymentMethod::Yape, 'reference' => '0081', 'paid_at' => now()]);
        $receipts = app(PaymentReceipt::class);

        // El pago anterior cubrió 100 de la limpieza: este termina la limpieza y cubre la curación.
        $this->assertSame([['description' => 'Limpieza dental', 'amount' => 20.0], ['description' => 'Curación con resina', 'amount' => 150.0], ['description' => 'A cuenta', 'amount' => 30.0]], $receipts->lines($payment));
        $this->assertSame(-30.0, $receipts->balanceAfter($payment));

        $reception = User::factory()->reception()->create();
        $this->actingAs($reception);
        $url = Filament::getPanel('admin')->route('print.receipt', ['payment' => $payment]);

        $this->get($url)
            ->assertOk()
            ->assertSee(['Constancia de pago', 'N.º '.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT), 'Rosa Huamán', 'Curación con resina', 'Total pagado', 'S/ 200.00', 'Yape · 0081', 'Saldo después de este pago: A favor S/ 30.00', 'Enviar por WhatsApp', 'no reemplaza la boleta ni la factura']);

        $location = $this->post(Filament::getPanel('admin')->route('print.receipt.whatsapp', ['payment' => $payment]))
            ->assertRedirectContains('https://wa.me/51987654321')
            ->headers->get('Location');
        $this->assertStringContainsString(rawurlencode('Recibimos tu pago de S/ 200.00 (yape) el '.now()->format('d/m/Y')), $location);
        $this->assertStringContainsString(rawurlencode('Saldo: A favor S/ 30.00'), $location);
        $this->assertSame(MessageStatus::Sent, WhatsappMessage::sole()->status);

        // Anulado: la hoja lo dice y ya no se envía.
        $payment->update(['voided_at' => now(), 'voided_by_user_id' => $reception->id, 'void_reason' => 'Monto mal escrito']);
        $this->get($url)->assertOk()->assertSee(['ANULADO', 'Monto mal escrito'])->assertDontSee('Enviar por WhatsApp');

        // Los doctores no ven la caja.
        $this->actingAs($this->doctorOffering(Treatment::factory()->create())->user);
        $this->get($url)->assertForbidden();
    }

    public function test_caja_and_the_patient_file_link_each_payment_to_its_receipt(): void
    {
        $payment = Payment::factory()->create(['amount' => 80]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManagePayments::class)
            ->assertActionHasUrl(TestAction::make('receipt')->table($payment), Filament::getPanel('admin')->route('print.receipt', ['payment' => $payment]))
            ->mountAction('registerPayment')
            ->assertActionDataSet(['patient_id' => null, 'amount' => null, 'method' => PaymentMethod::Cash]);
    }
}
