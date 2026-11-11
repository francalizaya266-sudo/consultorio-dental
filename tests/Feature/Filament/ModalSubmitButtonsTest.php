<?php

namespace Tests\Feature\Filament;

use App\Enums\MessageTemplateKey;
use App\Enums\PaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Widgets\FollowUpsToday;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/**
 * El botón de abajo de cada ventana tiene que enviarla. Si al personalizarlo se toma por error la acción misma,
 * el botón vuelve a abrir la ventana en vez de guardar: no pasa nada y no sale ningún error.
 */
class ModalSubmitButtonsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_the_dentist_who_runs_the_desk_collects_from_the_finished_attention(): void
    {
        $this->freezeMonday();
        $admin = User::factory()->admin(isDentist: true)->create();
        $doctor = Doctor::factory()->create(['user_id' => $admin->id]);
        $patient = Patient::factory()->create();
        $consultation = Consultation::factory()->for($patient)->for($doctor)->create(['closed_at' => now()]);
        Charge::factory()->for($patient)->create(['amount' => 50, 'charged_at' => now(), 'consultation_id' => $consultation->id]);
        $this->actingAs($admin);

        $page = Livewire::test(ViewConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee('Cobrar ahora')
            ->call('mountAction', 'collect')
            ->assertSchemaStateSet(['amount' => 50.0, 'method' => PaymentMethod::Cash], 'mountedActionSchema0');

        $this->assertSubmitsTheForm($page, 'Cobrar');

        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Pago registrado');

        $this->assertSame('50.00', Payment::sole()->amount);
    }

    public function test_every_collect_window_and_the_whatsapp_window_submit_themselves(): void
    {
        $this->freezeMonday();
        $carmen = Patient::factory()->create(['first_name' => 'Carmen', 'birth_date' => '1990-11-02']);
        $this->actingAs(User::factory()->reception()->create());

        $this->assertSubmitsTheForm(Livewire::test(Dashboard::class)->mountAction('registerPayment'), 'Cobrar');
        $this->assertSubmitsTheForm(
            Livewire::test(FollowUpsToday::class)->mountAction('whatsapp', ['template' => MessageTemplateKey::Birthday->value, 'patient' => $carmen->id]),
            'Abrir WhatsApp',
        );
    }

    private function assertSubmitsTheForm(Testable $component, string $label): void
    {
        $submit = $component->instance()->getMountedAction()->getModalSubmitAction();

        $this->assertSame('submit', $submit->getName());
        $this->assertSame($label, $submit->getLabel());
    }
}
