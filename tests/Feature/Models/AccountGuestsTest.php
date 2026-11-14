<?php

namespace Tests\Feature\Models;

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\PatientRelationship;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quién queda "por confirmar" en un celular: el que entró solo por una reserva de la web hecha sin el celular de la
 * familia, mientras nadie la confirme.
 */
class AccountGuestsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Account $account;

    private Patient $holder;

    private Patient $newcomer;

    /** La reserva del recién llegado: de la web, pendiente y sin el celular de la familia (así la crea la fábrica). */
    private Appointment $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->holder = Patient::factory()->for($this->account)->create();
        $this->newcomer = Patient::factory()->dependentOf($this->account, PatientRelationship::Other)->create();
        $this->booking = Appointment::factory()->for($this->newcomer)->create();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function waysOfBeingRecognized(): array
    {
        return [
            'la agendó el consultorio' => [['booked_via' => BookingChannel::Reception]],
            'se reservó desde el celular de la familia' => [['from_verified_device' => true]],
            'la confirmaron' => [['status' => AppointmentStatus::Confirmed, 'confirmed_at' => '2026-11-01 09:00:00']],
            'vino a la cita sin confirmarla' => [['status' => AppointmentStatus::Completed]],
        ];
    }

    /** @param  array<string, mixed>  $recognized */
    #[DataProvider('waysOfBeingRecognized')]
    public function test_a_newcomer_waits_while_the_family_is_known_and_stops_waiting_once_recognized_the_same_way(array $recognized): void
    {
        Appointment::factory()->for($this->holder)->create($recognized);

        $this->assertSame([$this->newcomer->id], $this->account->guestIds());

        $this->booking->update($recognized);

        $this->assertSame([], $this->account->guestIds());
    }

    public function test_on_a_phone_where_nobody_is_recognized_yet_nobody_waits(): void
    {
        // La primera reserva de un celular nuevo: la titular y el recién llegado reservaron igual, por la web.
        Appointment::factory()->for($this->holder)->create();
        // Un familiar que el consultorio registró sin cita no cuenta como "reconocido" ni queda por confirmar.
        Patient::factory()->dependentOf($this->account)->create();

        $this->assertSame([], $this->account->guestIds());
    }

    public function test_cancelling_the_booking_does_not_make_the_newcomer_family(): void
    {
        Appointment::factory()->for($this->holder)->confirmed()->create();
        $this->booking->update(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now()]);

        $this->assertSame([$this->newcomer->id], $this->account->guestIds());
    }

    public function test_another_phones_family_does_not_recognize_anyone_here(): void
    {
        Appointment::factory()->confirmed()->create();

        $this->assertSame([], $this->account->guestIds());
    }
}
