<?php

namespace Tests\Feature\Http\Controllers\Patient;

use App\Enums\AppointmentStatus;
use App\Enums\MagicLinkPurpose;
use App\Models\Appointment;
use App\Models\RememberedDevice;
use App\Services\Access\DeviceService;
use App\Services\Access\MagicLinkService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AppointmentLinkControllerTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    /** @return array{0: Appointment, 1: string} */
    private function appointmentWithLink(string $startsAt = '2026-11-04 10:00'): array
    {
        $appointment = Appointment::factory()->at($startsAt)->create();
        $url = app(MagicLinkService::class)->issue($appointment->patient->account, MagicLinkPurpose::Appointment, $appointment);

        return [$appointment, basename($url)];
    }

    public function test_opening_the_link_shows_the_appointment_without_confirming_it(): void
    {
        $this->freezeMonday();
        [$appointment, $token] = $this->appointmentWithLink();

        $this->get(route('patient.link', $token))
            ->assertOk()
            ->assertSee($appointment->patient->first_name)
            ->assertSee('Confirmar mi asistencia');

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
        $this->assertNull($appointment->patient->account->fresh()->phone_verified_at);
    }

    public function test_confirming_from_the_link_verifies_the_phone_and_remembers_the_device(): void
    {
        $this->freezeMonday();
        [$appointment, $token] = $this->appointmentWithLink();

        $this->post(route('patient.link.confirm', $token))
            ->assertRedirect(route('patient.link', $token))
            ->assertCookie(DeviceService::COOKIE);

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
        $this->assertNotNull($appointment->patient->account->fresh()->phone_verified_at);
        $this->assertSame(1, RememberedDevice::whereBelongsTo($appointment->patient->account)->count());
    }

    public function test_cancelling_from_the_link_cancels_the_appointment(): void
    {
        $this->freezeMonday();
        [$appointment, $token] = $this->appointmentWithLink();

        $this->post(route('patient.link.cancel', $token), ['reason' => 'Viaje'])
            ->assertRedirect(route('patient.link', $token));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertSame('Viaje', $appointment->fresh()->cancellation_reason);
    }

    public function test_cancelling_too_late_shows_the_reason_and_keeps_the_appointment(): void
    {
        $this->freezeMonday();
        [$appointment, $token] = $this->appointmentWithLink('2026-11-02 15:00');

        $this->post(route('patient.link.cancel', $token))
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
    }

    public function test_an_expired_link_shows_a_friendly_page(): void
    {
        $this->freezeMonday();
        [, $token] = $this->appointmentWithLink();
        $this->travel(10)->days();

        $this->get(route('patient.link', $token))
            ->assertOk()
            ->assertSee('Este enlace ya no está activo');
    }

    public function test_actions_on_an_unknown_link_return_404(): void
    {
        $this->post(route('patient.link.confirm', str_repeat('x', 32)))->assertNotFound();
    }
}
