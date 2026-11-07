<?php

namespace Tests\Feature\Filament;

use App\Enums\MessageStatus;
use App\Enums\PaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Account;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Billing\PaymentReceipt;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
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
