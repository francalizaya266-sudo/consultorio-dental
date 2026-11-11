<?php

namespace Tests\Feature\Filament;

use App\Enums\PaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class CashChangeTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_paying_in_cash_says_the_change_and_only_the_amount_is_saved(): void
    {
        $this->freezeMonday();
        $patient = Patient::factory()->create();
        Charge::factory()->for($patient)->create(['amount' => 80, 'charged_at' => now()]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Dashboard::class)
            ->mountAction('registerPayment')
            ->setActionData(['patient_id' => $patient->id])
            ->setActionData(['method' => PaymentMethod::Cash->value])
            ->assertMountedActionModalSee('¿Con cuánto paga?')
            ->assertMountedActionModalDontSee(['Vuelto', 'Faltan'])
            ->setActionData(['received' => 100])
            ->assertMountedActionModalSee('Vuelto: S/ 20.00')
            ->setActionData(['received' => 50])
            ->assertMountedActionModalSee('Faltan S/ 30.00')
            ->setActionData(['received' => 80])
            ->assertMountedActionModalSee('Paga justo, sin vuelto')
            // Con Yape no hay vuelto que dar.
            ->setActionData(['method' => PaymentMethod::Yape->value])
            ->assertMountedActionModalDontSee(['¿Con cuánto paga?', 'Paga justo'])
            ->setActionData(['method' => PaymentMethod::Cash->value, 'received' => 100])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $payment = Payment::sole();
        $this->assertSame(['80.00', PaymentMethod::Cash], [$payment->amount, $payment->method]);
        $this->assertSame(0.0, $patient->balance());
    }
}
