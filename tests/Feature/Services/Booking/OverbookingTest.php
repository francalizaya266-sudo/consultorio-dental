<?php

namespace Tests\Feature\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WhatsappMessage;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\Slot;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\WebAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class OverbookingTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private User $reception;

    private Treatment $treatment;

    private Doctor $doctor;

    private Appointment $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->reception = User::factory()->reception()->create();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->rosa = Appointment::factory()
            ->for($this->doctor)
            ->for($this->treatment)
            ->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))
            ->confirmed()
            ->at('2026-11-03 10:00')
            ->create();
    }

    public function test_booking_over_another_appointment_only_passes_when_it_is_asked_as_an_overbooking(): void
    {
        $this->assertSame(BookingException::overlaps($this->rosa)->getMessage(), $this->refusal('2026-11-03 10:00'));

        $urgent = $this->book('2026-11-03 10:00', ['overbook' => true]);

        $this->assertSame([true, AppointmentStatus::Confirmed, '10:30'], [$urgent->is_overbooked, $urgent->status, $urgent->ends_at->format('H:i')]);
        $this->assertSame('Sobrecupo: encima de la cita de Rosa Quispe.', $urgent->events()->where('type', 'created')->sole()->notes);
        // Al paciente se le avisa como en cualquier cita, y la de Rosa no se toca.
        $this->assertSame(1, WhatsappMessage::query()->where('appointment_id', $urgent->id)->count());
        $this->assertSame([AppointmentStatus::Confirmed, false], [$this->rosa->fresh()->status, $this->rosa->fresh()->is_overbooked]);
    }

    public function test_a_free_time_asked_as_an_overbooking_is_a_normal_appointment(): void
    {
        $appointment = $this->book('2026-11-03 16:00', ['overbook' => true]);

        $this->assertFalse($appointment->is_overbooked);
        $this->assertNull($appointment->events()->where('type', 'created')->sole()->notes);
    }

    public function test_an_overbooking_never_goes_over_a_block_or_a_time_held_for_the_waitlist(): void
    {
        // Vacaciones puestas con la cita de Rosa ya dada: ni así entra otra encima.
        $vacation = ScheduleBlock::query()->create(['doctor_id' => $this->doctor->id, 'type' => 'vacation', 'starts_at' => '2026-11-03 00:00', 'ends_at' => '2026-11-04 00:00']);
        $hold = WaitlistEntry::factory()->for($this->treatment)->offered()->create([
            'offered_starts_at' => '2026-11-04 16:00',
            'offered_until' => '2026-11-04 16:40',
            'offered_doctor_id' => $this->doctor->id,
            'held_until' => now()->addHours(2),
        ]);

        $this->assertSame(BookingException::overlaps($vacation)->getMessage(), $this->refusal('2026-11-03 10:00', ['overbook' => true]));
        $this->assertSame(BookingException::held($hold)->getMessage(), $this->refusal('2026-11-04 16:00', ['overbook' => true]));
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_time_of_an_overbooking_is_still_taken_for_the_agenda_and_the_web(): void
    {
        $this->book('2026-11-03 10:00', ['overbook' => true]);
        $day = CarbonImmutable::parse('2026-11-03');

        $staff = app(AvailabilityService::class)->slotsFor($this->doctor, $this->treatment, $day)->map(fn (Slot $slot) => $slot->label());
        $web = app(WebAvailability::class)->slots($this->treatment, collect([$this->doctor]), $day)->pluck('time');

        foreach ([$staff, $web] as $offered) {
            $this->assertContains('10:40', $offered);
            $this->assertEmpty($offered->intersect(['09:30', '09:45', '10:00', '10:15', '10:30'])->all());
        }
    }

    public function test_moving_an_overbooking_takes_it_to_a_free_time_and_clears_the_mark(): void
    {
        $urgent = $this->book('2026-11-03 10:00', ['overbook' => true]);

        $moved = app(AppointmentService::class)->rescheduleByStaff($urgent, CarbonImmutable::parse('2026-11-03 16:00'), $this->reception);

        $this->assertSame(['16:00', false], [$moved->starts_at->format('H:i'), $moved->is_overbooked]);
    }

    public function test_a_cancelled_overbooking_comes_back_with_undo_unless_the_time_was_blocked_meanwhile(): void
    {
        $service = app(AppointmentService::class);
        $urgent = $this->book('2026-11-03 10:00', ['overbook' => true]);
        $service->cancelByStaff($urgent, $this->reception);

        // La cita de Rosa sigue ahí: el sobrecupo ya iba encima de ella.
        $service->undoLastStep($urgent->fresh(), $this->reception);
        $this->assertSame([AppointmentStatus::Confirmed, true], [$urgent->fresh()->status, $urgent->fresh()->is_overbooked]);

        $service->cancelByStaff($urgent->fresh(), $this->reception);
        ScheduleBlock::query()->create(['doctor_id' => null, 'type' => 'holiday', 'starts_at' => '2026-11-03 00:00', 'ends_at' => '2026-11-04 00:00']);

        $this->expectExceptionObject(BookingException::slotNoLongerFree());

        $service->undoLastStep($urgent->fresh(), $this->reception);
    }

    /** @param  array<string, mixed>  $options */
    private function book(string $startsAt, array $options = []): Appointment
    {
        return app(AppointmentService::class)->bookFromStaff($this->reception, Patient::factory()->create(), $this->doctor, $this->treatment, CarbonImmutable::parse($startsAt), $options);
    }

    /**
     * Por qué no se pudo agendar ahí.
     *
     * @param  array<string, mixed>  $options
     */
    private function refusal(string $startsAt, array $options = []): string
    {
        try {
            $this->book($startsAt, $options);
        } catch (BookingException $e) {
            return $e->getMessage();
        }

        return 'Se agendó.';
    }
}
