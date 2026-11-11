<?php

namespace Tests\Feature\Http\Middleware;

use App\Enums\MessageTemplateKey;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Appointment;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/** Con solo usar el panel, los recordatorios quedan al día: no dependen de la tarea programada del hosting. */
class KeepRemindersUpToDateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_opening_the_panel_prepares_the_reminders_that_are_due(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $appointment = Appointment::factory()->at('2026-11-03 10:00')->create();
        $this->actingAs(User::factory()->reception()->create());

        $this->travelTo('2026-11-02 09:30');
        $this->get(WhatsappMessageResource::getUrl())
            ->assertOk()
            ->assertSee('Recordatorio de la cita')
            ->assertSee('te recordamos tu cita de mañana martes 3 de noviembre')
            // Sin avisos con fecha de salida, esa pestaña no estorba.
            ->assertDontSee('Programados');

        $this->assertTrue(WhatsappMessage::sole()->appointment->is($appointment));
        $this->assertSame(MessageTemplateKey::Reminder, WhatsappMessage::sole()->template_key);
    }

    public function test_it_catches_up_at_most_once_every_ten_minutes(): void
    {
        $this->travelTo('2026-10-26 10:00');
        Appointment::factory()->at('2026-11-03 10:00')->create();
        $this->actingAs(User::factory()->reception()->create());

        $this->travelTo('2026-11-02 08:55');
        $this->get(WhatsappMessageResource::getUrl())->assertOk();
        $this->travelTo('2026-11-02 09:01');
        $this->get(WhatsappMessageResource::getUrl())->assertOk();
        $this->assertSame(0, WhatsappMessage::count());

        $this->travelTo('2026-11-02 09:06');
        $this->get(WhatsappMessageResource::getUrl())->assertOk();
        $this->assertSame(1, WhatsappMessage::count());
    }
}
