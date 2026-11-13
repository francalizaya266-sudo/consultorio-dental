<?php

namespace Tests\Feature\Services\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AppointmentChangesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private User $reception;

    private Treatment $cleaning;

    private Treatment $filling;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->reception = User::factory()->reception()->create();
        $this->cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $this->filling = Treatment::factory()->create(['name' => 'Curación con resina', 'duration_minutes' => 60]);
        $this->doctor = $this->doctorOffering($this->cleaning, $this->filling);
    }

    public function test_the_treatment_changes_without_cancelling_and_the_appointment_takes_its_usual_duration(): void
    {
        $appointment = $this->book('2026-11-03 10:00');

        $changed = $this->service()->changeTreatment($appointment, $this->reception, $this->filling);

        $this->assertSame(
            [$this->filling->id, '2026-11-03 10:00', '11:00', false, AppointmentStatus::Confirmed],
            [$changed->treatment_id, $changed->starts_at->format('Y-m-d H:i'), $changed->ends_at->format('H:i'), $changed->custom_duration, $changed->status],
        );
        $this->assertSame(
            "Limpieza dental (30\u{00A0}min) → Curación con resina (1\u{00A0}h)",
            $changed->events()->where('type', 'changed')->sole()->notes,
        );
        // El aviso que todavía no se envió ya dice el tratamiento nuevo.
        $this->assertSame('curación con resina', WhatsappMessage::sole()->params['tratamiento']);
    }

    public function test_a_duration_set_by_hand_is_kept_when_the_appointment_moves(): void
    {
        $long = $this->service()->changeTreatment($this->book('2026-11-03 10:00'), $this->reception, $this->cleaning, 45);
        $usual = $this->book('2026-11-03 16:00');

        $this->assertSame(['10:45', true], [$long->ends_at->format('H:i'), $long->custom_duration]);

        $long = $this->service()->rescheduleByStaff($long, CarbonImmutable::parse('2026-11-04 16:00'), $this->reception);
        // La que no se tocó sigue lo que el doctor tarda hoy en ese tratamiento.
        $this->doctor->treatments()->updateExistingPivot($this->cleaning->id, ['duration_minutes' => 40]);
        $usual = $this->service()->rescheduleByStaff($usual, CarbonImmutable::parse('2026-11-05 16:00'), $this->reception);

        $this->assertSame(['16:45', true], [$long->ends_at->format('H:i'), $long->custom_duration]);
        $this->assertSame(['16:40', false], [$usual->ends_at->format('H:i'), $usual->custom_duration]);
    }

    public function test_going_back_to_the_usual_duration_stops_counting_as_set_by_hand(): void
    {
        $appointment = $this->service()->changeTreatment($this->book('2026-11-03 10:00'), $this->reception, $this->cleaning, 45);

        $appointment = $this->service()->changeTreatment($appointment, $this->reception, $this->cleaning, 30);

        $this->assertSame(['10:30', false], [$appointment->ends_at->format('H:i'), $appointment->custom_duration]);
    }

    public function test_a_longer_duration_is_refused_when_it_runs_into_the_next_appointment_or_a_block(): void
    {
        // Con más citas ese día, el mensaje igual nombra a quien sigue.
        $this->book('2026-11-03 09:00');
        $first = $this->book('2026-11-03 10:00');
        $next = $this->book('2026-11-03 10:40');
        $beforeLunch = $this->book('2026-11-04 12:00');
        $lunch = ScheduleBlock::query()->create(['doctor_id' => $this->doctor->id, 'type' => 'other', 'starts_at' => '2026-11-04 12:45', 'ends_at' => '2026-11-04 15:00', 'reason' => 'Almuerzo']);

        $message = function (Appointment $appointment, int $minutes): string {
            try {
                $this->service()->changeTreatment($appointment, $this->reception, $this->cleaning, $minutes);
            } catch (BookingException $e) {
                return $e->getMessage();
            }

            return 'Se cambió.';
        };

        $this->assertSame(BookingException::tooLong($next)->getMessage(), $message($first, 45));
        $this->assertSame('10:30', $first->fresh()->ends_at->format('H:i'));
        // Hasta donde empieza el bloqueo sí entra; un minuto más, no.
        $this->assertSame('Se cambió.', $message($beforeLunch, 45));
        $this->assertSame(BookingException::tooLong($lunch)->getMessage(), $message($beforeLunch->fresh(), 60));
        $this->assertStringContainsString('Ponle menos tiempo o muévela a otro horario.', BookingException::tooLong($next)->getMessage());
    }

    public function test_a_shorter_duration_always_fits(): void
    {
        $first = $this->service()->changeTreatment($this->book('2026-11-03 10:00'), $this->reception, $this->cleaning, 60);
        $this->book('2026-11-03 11:10');

        $shorter = $this->service()->changeTreatment($first, $this->reception, $this->cleaning, 20);

        $this->assertSame(['10:20', true], [$shorter->ends_at->format('H:i'), $shorter->custom_duration]);
    }

    public function test_the_doctor_must_offer_the_new_treatment(): void
    {
        $appointment = $this->book('2026-11-03 10:00');
        $implant = Treatment::factory()->create(['name' => 'Implante dental']);

        $this->expectExceptionObject(BookingException::doctorDoesNotOfferTreatment());

        $this->service()->changeTreatment($appointment, $this->reception, $implant);
    }

    public function test_an_appointment_already_in_attention_or_closed_cannot_change(): void
    {
        $inRoom = $this->book('2026-11-03 10:00');
        $inRoom->update(['status' => AppointmentStatus::CheckedIn]);
        $attended = $this->book('2026-11-03 16:00');
        $attended->update(['status' => AppointmentStatus::Completed]);

        // En la sala todavía se puede: ahí es cuando se sabe que venía por otra cosa.
        $this->assertSame($this->filling->id, $this->service()->changeTreatment($inRoom, $this->reception, $this->filling)->treatment_id);

        $this->expectExceptionObject(BookingException::notChangeable());

        $this->service()->changeTreatment($attended, $this->reception, $this->filling);
    }

    public function test_with_another_treatment_the_appointment_stops_being_a_session_of_the_plan(): void
    {
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()->subWeek()]);
        $item = $plan->items()->create(['treatment_id' => $this->cleaning->id, 'description' => 'Limpieza dental', 'unit_price' => 80, 'sessions_planned' => 2, 'accepted_at' => now()->subWeek()]);
        $session = $this->book('2026-11-03 10:00', ['treatment_plan_item_id' => $item->id]);
        $same = $this->book('2026-11-03 16:00', ['treatment_plan_item_id' => $item->id]);

        $this->assertNull($this->service()->changeTreatment($session, $this->reception, $this->filling)->treatment_plan_item_id);
        // Solo más tiempo para lo mismo: sigue siendo esa sesión.
        $this->assertSame($item->id, $this->service()->changeTreatment($same, $this->reception, $this->cleaning, 45)->treatment_plan_item_id);
    }

    public function test_a_doctor_only_changes_the_appointments_of_their_own_agenda(): void
    {
        $appointment = $this->book('2026-11-03 10:00');
        $other = Doctor::factory()->create()->user;

        $this->expectExceptionMessage('Solo puedes manejar tu propia agenda.');

        $this->service()->changeTreatment($appointment, $other, $this->filling);
    }

    /** @param  array<string, mixed>  $options */
    private function book(string $startsAt, array $options = []): Appointment
    {
        return $this->service()->bookFromStaff($this->reception, Patient::factory()->create(), $this->doctor, $this->cleaning, CarbonImmutable::parse($startsAt), $options);
    }

    private function service(): AppointmentService
    {
        return app(AppointmentService::class);
    }
}
