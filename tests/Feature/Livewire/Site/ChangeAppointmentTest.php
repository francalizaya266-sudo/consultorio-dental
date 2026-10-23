<?php

namespace Tests\Feature\Livewire\Site;

use App\Enums\AppointmentStatus;
use App\Enums\MagicLinkPurpose;
use App\Enums\RescheduleRequestStatus;
use App\Livewire\Site\ChangeAppointment;
use App\Models\Appointment;
use App\Models\RescheduleRequest;
use App\Models\Treatment;
use App\Services\Access\MagicLinkService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class ChangeAppointmentTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    /** @return array{0: Appointment, 1: string} */
    private function appointmentWithLink(string $startsAt, array $attributes = []): array
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at($startsAt)->create($attributes);
        $url = app(MagicLinkService::class)->issue($appointment->patient->account, MagicLinkPurpose::Appointment, $appointment);

        return [$appointment, basename($url)];
    }

    public function test_more_than_a_day_ahead_the_patient_moves_the_appointment_at_once(): void
    {
        $this->freezeMonday();
        [$appointment, $token] = $this->appointmentWithLink('2026-11-04 10:00');

        Livewire::test(ChangeAppointment::class, ['appointment' => $appointment, 'token' => $token])
            ->assertSee('El cambio se hace al instante.')
            ->call('selectDate', '2026-11-05')
            ->call('pick', '11:00')
            ->call('pick', '12:00')
            ->assertSet('chosen', ['2026-11-05 12:00'])
            ->call('save')
            ->assertRedirect(route('patient.link', $token));

        $appointment->refresh();
        $this->assertSame('2026-11-05 12:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);

        // El enlace sigue sirviendo para la nueva fecha.
        $this->get(route('patient.link', $token))->assertOk()->assertSee('Jueves 5 de noviembre');
    }

    public function test_close_to_the_appointment_the_patient_proposes_up_to_three_times(): void
    {
        $this->freezeMonday();
        [$appointment, $token] = $this->appointmentWithLink('2026-11-02 16:00');

        Livewire::test(ChangeAppointment::class, ['appointment' => $appointment, 'token' => $token])
            ->assertSee('el consultorio confirma el cambio')
            ->call('selectDate', '2026-11-03')
            ->call('pick', '09:00')
            ->call('pick', '10:00')
            ->call('pick', '11:00')
            ->call('pick', '12:00')
            ->assertSee('Puedes proponer hasta 3 horarios. Quita uno para elegir otro.')
            ->call('pick', '10:00')
            ->assertSet('chosen', ['2026-11-03 09:00', '2026-11-03 11:00'])
            ->set('reason', 'Me salió una reunión')
            ->call('save')
            ->assertRedirect(route('patient.link', $token));

        $request = RescheduleRequest::with('options')->sole();
        $this->assertSame(RescheduleRequestStatus::Pending, $request->status);
        $this->assertSame(['2026-11-03 09:00', '2026-11-03 11:00'], $request->options->map(fn ($o) => $o->starts_at->format('Y-m-d H:i'))->all());
        $this->assertSame('2026-11-02 16:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));

        $this->get(route('patient.link', $token))->assertSee('Pediste cambiar esta cita a:');
    }

    public function test_a_fixed_date_session_always_needs_the_clinic(): void
    {
        $this->freezeMonday();
        [$appointment, $token] = $this->appointmentWithLink('2026-11-10 10:00', ['fixed_date' => true]);

        Livewire::test(ChangeAppointment::class, ['appointment' => $appointment, 'token' => $token])
            ->assertSee('Esta sesión tiene fecha coordinada con tu doctor');
    }

    public function test_a_link_only_opens_its_own_appointment(): void
    {
        $this->freezeMonday();
        [$appointment] = $this->appointmentWithLink('2026-11-04 10:00');
        // Un nombre que el generador de datos nunca da: la otra cita (paciente y doctor) usa nombres al azar.
        $appointment->patient->update(['first_name' => 'Hermenegilda']);
        [, $otherToken] = $this->appointmentWithLink('2026-11-04 11:00');

        $this->get(route('patient.link.change', $otherToken))->assertOk()->assertDontSee('Hermenegilda');
        $this->get(route('site.account.change', $appointment))->assertForbidden();
    }
}
