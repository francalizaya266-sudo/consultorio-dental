<?php

namespace Tests\Feature\Http\Controllers\Site;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\Treatment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AppointmentCalendarControllerTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private function calendarUrl(Appointment $appointment): string
    {
        return URL::signedRoute('site.booking.calendar', ['appointment' => $appointment], absolute: false);
    }

    public function test_the_signed_address_gives_the_appointment_as_a_calendar_file(): void
    {
        $this->freezeMonday();
        Setting::set('clinic.name', 'Clínica Vega Implant');
        Setting::set('clinic.address', 'Jr. Apurímac 648, segundo piso; frente a la plaza');
        Setting::set('clinic.district', 'Juliaca');
        $appointment = Appointment::factory()
            ->for(Patient::factory()->state(['first_name' => 'María']))
            ->for(Doctor::factory()->state(['name' => 'Dra. Vega']))
            ->for(Treatment::factory()->state(['name' => 'Limpieza dental, con flúor']))
            ->at('2026-11-04 10:00', minutes: 45)
            ->create();

        $response = $this->get($this->calendarUrl($appointment))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

        // Las líneas largas van partidas: se juntan como lo hace el calendario del celular.
        $lines = explode("\r\n", str_replace("\r\n ", '', $response->getContent()));

        // 10:00 a. m. en Lima son las 15:00 en la hora universal que usan los calendarios.
        $this->assertContains('DTSTART:20261104T150000Z', $lines);
        $this->assertContains('DTEND:20261104T154500Z', $lines);
        $this->assertContains('SUMMARY:Limpieza dental\, con flúor · Clínica Vega Implant', $lines);
        $this->assertContains('LOCATION:Jr. Apurímac 648\, segundo piso\; frente a la plaza\, Juliaca', $lines);
        $this->assertContains('DESCRIPTION:Cita de María con Dra. Vega.', $lines);
        $this->assertContains('TRIGGER:-PT120M', $lines);

        foreach (explode("\r\n", $response->getContent()) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    public function test_without_the_signature_or_with_another_appointment_the_file_is_refused(): void
    {
        $this->freezeMonday();
        $appointment = Appointment::factory()->at('2026-11-04 10:00')->create();
        $other = Appointment::factory()->at('2026-11-05 10:00')->create();

        $this->get(route('site.booking.calendar', $appointment))->assertForbidden();
        $this->get(str_replace("/{$appointment->id}/", "/{$other->id}/", $this->calendarUrl($appointment)))->assertForbidden();
    }

    public function test_a_cancelled_appointment_has_no_calendar_file(): void
    {
        $this->freezeMonday();
        $appointment = Appointment::factory()->at('2026-11-04 10:00')->cancelled()->create();

        $this->get($this->calendarUrl($appointment))->assertNotFound();
    }
}
