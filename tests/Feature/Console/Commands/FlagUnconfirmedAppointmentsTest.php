<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class FlagUnconfirmedAppointmentsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_flags_tomorrows_unconfirmed_appointments_so_reception_calls(): void
    {
        $this->freezeMonday();
        $reception = User::factory()->reception()->create();
        $pending = Appointment::factory()->at('2026-11-03 10:00')->create();
        $confirmed = Appointment::factory()->at('2026-11-03 11:00')->confirmed()->create();

        $this->artisan('citas:marcar-sin-confirmar')->assertSuccessful();

        $this->assertTrue($pending->fresh()->needsCall());
        $this->assertFalse($confirmed->fresh()->needsCall());
        $this->assertSame(1, $reception->notifications()->count());
    }
}
