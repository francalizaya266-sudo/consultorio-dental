<?php

namespace Tests\Feature\Console\Commands;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class FlagUnconfirmedAppointmentsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_flags_tomorrows_unconfirmed_appointments_so_reception_calls(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $reception = User::factory()->reception()->create();
        $pending = Appointment::factory()->at('2026-11-03 10:00')->create();
        $confirmed = Appointment::factory()->at('2026-11-03 11:00')->confirmed()->create();

        $this->travelTo('2026-11-02 15:00');
        $this->artisan('citas:marcar-sin-confirmar')->assertSuccessful();
        $this->assertFalse($pending->fresh()->needsCall());

        // Una que se pide después de esa hora no se marca: el paciente acaba de reservarla.
        $this->travelTo('2026-11-02 16:20');
        $justBooked = Appointment::factory()->at('2026-11-03 12:00')->create();
        $this->artisan('citas:marcar-sin-confirmar')->assertSuccessful();
        $this->artisan('citas:marcar-sin-confirmar')->assertSuccessful();

        $this->assertTrue($pending->fresh()->needsCall());
        $this->assertFalse($confirmed->fresh()->needsCall());
        $this->assertFalse($justBooked->fresh()->needsCall());

        // Una sola alerta, que dice de qué día son y lleva a la lista.
        $alert = $reception->notifications()->sole()->data;
        $this->assertSame('1 cita de mañana sin confirmar', $alert['title']);
        $this->assertSame(AppointmentResource::getUrl('index', ['tab' => 'pending']), $alert['actions'][0]['url']);
    }

    public function test_mondays_unconfirmed_appointments_are_flagged_on_saturday(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $reception = User::factory()->reception()->create();
        $doctor = Doctor::factory()->withSchedule()->create();
        $monday = Appointment::factory()->for($doctor)->at('2026-11-09 10:00')->count(2)->sequence(['starts_at' => '2026-11-09 10:00', 'ends_at' => '2026-11-09 10:30'], ['starts_at' => '2026-11-09 11:00', 'ends_at' => '2026-11-09 11:30'])->create();

        $this->travelTo('2026-11-07 16:05');
        $this->artisan('citas:marcar-sin-confirmar')->assertSuccessful();

        $this->assertTrue($monday->every(fn (Appointment $appointment) => $appointment->fresh()->needsCall()));
        $this->assertSame('2 citas del lunes 9 sin confirmar', $reception->notifications()->sole()->data['title']);
    }
}
