<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\MessageTemplateKey;
use App\Models\Appointment;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PrepareRemindersTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_prepares_one_reminder_per_appointment_tomorrow(): void
    {
        $this->freezeMonday();
        $tomorrow = Appointment::factory()->at('2026-11-03 10:00')->create();
        Appointment::factory()->at('2026-11-03 11:00')->cancelled()->create();
        Appointment::factory()->at('2026-11-04 10:00')->create();

        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();

        $reminder = WhatsappMessage::sole();
        $this->assertSame(MessageTemplateKey::Reminder, $reminder->template_key);
        $this->assertTrue($reminder->appointment->is($tomorrow));
    }
}
