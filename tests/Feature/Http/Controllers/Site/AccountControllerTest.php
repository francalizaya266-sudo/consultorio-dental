<?php

namespace Tests\Feature\Http\Controllers\Site;

use App\Enums\AppointmentStatus;
use App\Enums\MagicLinkPurpose;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\RememberedDevice;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Access\DeviceService;
use App\Services\Access\MagicLinkService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Account $account;

    private Patient $mother;

    private Patient $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->account = Account::factory()->verified()->create(['phone' => '+51987654321']);
        $this->mother = Patient::factory()->for($this->account)->create(['first_name' => 'Rosa', 'document_number' => '40123456']);
        $this->child = Patient::factory()->dependentOf($this->account)->create(['first_name' => 'Mateo']);
    }

    private function rememberDevice(?Account $account = null): static
    {
        RememberedDevice::create(['account_id' => ($account ?? $this->account)->id, 'token_hash' => hash('sha256', 'este-celular'), 'expires_at' => now()->addMonth()]);

        return $this->withCookie(DeviceService::COOKIE, 'este-celular');
    }

    public function test_a_new_device_sees_how_to_ask_for_its_link(): void
    {
        $this->get(route('site.account'))
            ->assertOk()
            ->assertSee('Pedir mi enlace por WhatsApp')
            ->assertDontSee('Mateo');
    }

    public function test_a_remembered_device_sees_the_family_upcoming_appointments_but_not_personal_data(): void
    {
        $upcoming = Appointment::factory()->for($this->child)->at('2026-11-04 10:00')->confirmed()->create();
        Appointment::factory()->for($this->mother)->at('2026-10-20 10:00')->create(['status' => AppointmentStatus::Completed]);
        $stranger = Appointment::factory()->at('2026-11-04 11:00')->create();

        $this->rememberDevice()
            ->get(route('site.account'))
            ->assertOk()
            ->assertSee('Miércoles 4 de noviembre')
            ->assertSee($upcoming->treatment->name)
            ->assertSee('Mateo')
            ->assertDontSee($stranger->patient->first_name)
            ->assertSee('Para cuidar tus datos')
            ->assertDontSee('40123456')
            ->assertDontSee('20/10/2026');
    }

    public function test_entering_with_the_access_link_remembers_the_device_and_shows_history_for_a_while(): void
    {
        Appointment::factory()->for($this->mother)->at('2026-10-20 10:00')->create(['status' => AppointmentStatus::Completed]);
        $token = basename(app(MagicLinkService::class)->issue($this->account, MagicLinkPurpose::Access));

        $this->get(route('patient.link', $token))->assertOk()->assertSee('Entrar a Mi cuenta');

        $response = $this->post(route('patient.link.enter', $token))
            ->assertRedirect(route('site.account'))
            ->assertCookie(DeviceService::COOKIE);

        $this->assertSame(1, RememberedDevice::whereBelongsTo($this->account)->count());

        $device = $response->getCookie(DeviceService::COOKIE)->getValue();
        $this->withCookie(DeviceService::COOKIE, $device)
            ->get(route('site.account'))
            ->assertSee('40123456')
            ->assertSee('20/10/2026');

        $this->travel(20)->minutes();

        $this->withCookie(DeviceService::COOKIE, $device)
            ->get(route('site.account'))
            ->assertSee('Hola, Rosa')
            ->assertSee('Para cuidar tus datos')
            ->assertDontSee('40123456');
    }

    public function test_asking_for_access_leaves_one_link_ready_for_reception_without_revealing_numbers(): void
    {
        $reception = User::factory()->reception()->create();

        $this->from(route('site.account'))
            ->post(route('site.account.access'), ['phone' => '987 654 321'])
            ->assertRedirect(route('site.account'))
            ->assertSessionHas('access_requested', '+51987654321');

        $this->from(route('site.account'))->post(route('site.account.access'), ['phone' => '987654321']);

        $this->from(route('site.account'))
            ->post(route('site.account.access'), ['phone' => '911 111 111'])
            ->assertSessionHas('access_requested', '+51911111111');

        $this->from(route('site.account'))
            ->post(route('site.account.access'), ['phone' => '12345'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(1, WhatsappMessage::where('template_key', MessageTemplateKey::AccessLink)->count());
        $this->assertSame(1, $reception->notifications()->count());

        // Recepción lo envía desde la ficha del celular y el pedido queda atendido.
        $this->actingAs($reception);
        Livewire::test(ViewAccount::class, ['record' => $this->account->getKey()])
            ->callAction('sendAccessLink', data: ['body' => 'Hola Rosa, entra a Mi cuenta aquí.'])
            ->assertHasNoActionErrors();

        $messages = WhatsappMessage::where('template_key', MessageTemplateKey::AccessLink)->get();
        $this->assertSame([MessageStatus::Cancelled, MessageStatus::Sent], $messages->sortBy('id')->pluck('status')->all());
    }

    public function test_the_family_confirms_and_cancels_from_my_account(): void
    {
        $appointment = Appointment::factory()->for($this->child)->at('2026-11-04 10:00')->create();
        $this->rememberDevice();

        $this->from(route('site.account'))->post(route('site.account.confirm', $appointment))->assertSessionHas('status');
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);

        $this->get(route('site.account.change', $appointment))->assertOk()->assertSee('Cambiar el horario');

        $this->from(route('site.account'))->post(route('site.account.cancel', $appointment))->assertSessionHas('status', 'La cita fue cancelada.');
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_another_familys_appointment_is_off_limits(): void
    {
        $stranger = Appointment::factory()->at('2026-11-04 10:00')->create();
        $this->rememberDevice();

        $this->post(route('site.account.confirm', $stranger))->assertForbidden();
        $this->post(route('site.account.cancel', $stranger))->assertForbidden();
        $this->get(route('site.account.change', $stranger))->assertForbidden();

        $this->assertSame(AppointmentStatus::Pending, $stranger->fresh()->status);
    }

    public function test_leaving_forgets_this_device(): void
    {
        $this->rememberDevice()
            ->post(route('site.account.logout'))
            ->assertRedirect(route('site.account'));

        $this->assertNotNull(RememberedDevice::sole()->revoked_at);
        $this->get(route('site.account'))->assertSee('Pedir mi enlace por WhatsApp');
    }
}
