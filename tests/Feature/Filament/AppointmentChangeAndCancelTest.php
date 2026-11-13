<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\CancelledBy;
use App\Enums\MessageTemplateKey;
use App\Enums\WaitlistStatus;
use App\Exceptions\BookingException;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\Concerns\ReadsNotifications;
use Tests\TestCase;

/** Cambiar el tratamiento o la duración, editar las notas y cancelar con motivo, desde donde se ve la cita. */
class AppointmentChangeAndCancelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase, ReadsNotifications;

    private User $reception;

    private Treatment $cleaning;

    private Treatment $filling;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $this->filling = Treatment::factory()->create(['name' => 'Curación con resina', 'duration_minutes' => 60]);
        $this->doctor = $this->doctorOffering($this->cleaning, $this->filling);
        $this->reception = User::factory()->reception()->create();
        $this->actingAs($this->reception);
    }

    public function test_the_change_window_follows_the_new_treatment_with_its_usual_duration(): void
    {
        $appointment = $this->book('2026-11-03 10:00');

        $this->page($appointment)
            ->mountAction('changeTreatment')
            ->assertActionDataSet(['treatment_id' => $this->cleaning->id, 'duration_minutes' => 30])
            ->setActionData(['treatment_id' => $this->filling->id])
            ->assertActionDataSet(['duration_minutes' => 60])
            ->assertMountedActionModalSee('De 10:00 a 11:00.')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Cita actualizada');

        $appointment->refresh();
        $this->assertSame([$this->filling->id, '2026-11-03 10:00', '11:00'], [$appointment->treatment_id, $appointment->starts_at->format('Y-m-d H:i'), $appointment->ends_at->format('H:i')]);
    }

    public function test_only_the_treatments_of_that_doctor_can_be_chosen(): void
    {
        $implant = Treatment::factory()->create(['name' => 'Implante dental', 'duration_minutes' => 60]);
        $appointment = $this->book('2026-11-03 10:00');

        $this->page($appointment)
            ->mountAction('changeTreatment')
            ->setActionData(['treatment_id' => $implant->id])
            ->callMountedAction()
            ->assertHasFormErrors(['treatment_id']);

        $this->assertSame($this->cleaning->id, $appointment->fresh()->treatment_id);
    }

    public function test_a_duration_that_runs_into_the_next_appointment_is_refused_and_says_with_whom(): void
    {
        $appointment = $this->book('2026-11-03 10:00');
        $next = $this->book('2026-11-03 10:40');

        $this->page($appointment)
            ->mountAction('changeTreatment')
            ->setActionData(['duration_minutes' => 60])
            ->callMountedAction()
            ->assertNotified(BookingException::tooLong($next)->getMessage());

        $this->assertSame('10:30', $appointment->fresh()->ends_at->format('H:i'));
    }

    public function test_the_change_is_offered_until_the_patient_is_in_the_room(): void
    {
        $inRoom = $this->book('2026-11-02 10:00');
        $inRoom->update(['status' => AppointmentStatus::CheckedIn]);
        $inAttention = $this->book('2026-11-02 11:00');
        $inAttention->update(['status' => AppointmentStatus::InProgress]);

        $this->page($inRoom)->assertActionVisible('changeTreatment');
        $this->page($inAttention)->assertActionHidden('changeTreatment');
    }

    public function test_notes_are_edited_from_the_agenda_panel_and_from_the_list(): void
    {
        $appointment = $this->book('2026-11-02 10:00');
        $cancelled = $this->book('2026-11-02 16:00');
        app(AppointmentService::class)->cancelByStaff($cancelled, $this->reception);

        Livewire::test(Agenda::class)
            ->mountAction('appointment', ['id' => $appointment->id])
            ->mountAction('notes')
            ->setActionData(['internal_notes' => 'Viene con su mamá'])
            ->callMountedAction()
            ->assertNotified('Notas guardadas');

        $this->assertSame(['Viene con su mamá', false], [$appointment->fresh()->internal_notes, $appointment->fresh()->fixed_date]);

        Livewire::test(ListAppointments::class)
            ->callAction(TestAction::make('notes')->table($appointment), ['internal_notes' => 'Llamar al llegar', 'fixed_date' => true])
            ->assertHasNoActionErrors();

        $this->assertSame(['Llamar al llegar', true], [$appointment->fresh()->internal_notes, $appointment->fresh()->fixed_date]);
        // Una cancelada ya no se anota.
        $this->page($cancelled->fresh())->assertActionHidden('notes');
    }

    public function test_cancelling_asks_who_cancels_and_offers_the_reasons_of_each_one(): void
    {
        $appointment = $this->book('2026-11-03 10:00');

        $this->page($appointment)
            ->mountAction('cancel')
            ->assertFormFieldHidden('motive')
            ->callMountedAction()
            ->assertHasFormErrors(['who' => 'Marca si cancela el paciente o el consultorio.'])
            ->setActionData(['who' => CancelledBy::Patient->value])
            ->assertMountedActionModalSee(['Está enfermo', 'Por el costo'])
            ->assertMountedActionModalDontSee('Se agendó por error')
            // El paciente que cancela ya lo sabe; si cancela el consultorio, hay que avisarle.
            ->assertActionDataSet(['notify' => false])
            ->setActionData(['motive' => 'Está enfermo'])
            ->setActionData(['who' => CancelledBy::Clinic->value])
            ->assertMountedActionModalSee('El doctor no podrá atender')
            ->assertMountedActionModalDontSee('Está enfermo')
            ->assertActionDataSet(['notify' => true, 'motive' => null]);

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_a_patient_who_calls_to_cancel_is_recorded_with_the_reason_and_can_still_be_undone(): void
    {
        $appointment = $this->book('2026-11-03 10:00');
        WhatsappMessage::query()->delete();

        $this->page($appointment)
            ->mountAction('cancel')
            ->setActionData(['who' => CancelledBy::Patient->value])
            ->setActionData(['motive' => 'Está enfermo', 'reason' => 'Tiene fiebre'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Cita cancelada');

        $appointment->refresh();
        $this->assertSame(
            [AppointmentStatus::Cancelled, CancelledBy::Patient, 'Está enfermo: Tiene fiebre', $this->reception->id],
            [$appointment->status, $appointment->cancelled_by, $appointment->cancellation_reason, $appointment->cancelled_by_user_id],
        );
        $this->assertSame(0, WhatsappMessage::query()->where('template_key', MessageTemplateKey::Cancelled)->count());
        // Lo anotó recepción: si fue un error, todavía se deshace.
        $this->assertSame(AppointmentStatus::Cancelled, app(AppointmentService::class)->undoableStep($appointment));
    }

    public function test_when_the_clinic_cancels_the_patient_gets_the_notice_and_other_keeps_only_the_detail(): void
    {
        $appointment = $this->book('2026-11-03 10:00');

        $this->page($appointment)
            ->mountAction('cancel')
            ->setActionData(['who' => CancelledBy::Clinic->value])
            ->setActionData(['motive' => 'Otro', 'reason' => 'Se malogró el equipo'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([CancelledBy::Clinic, 'Se malogró el equipo'], [$appointment->fresh()->cancelled_by, $appointment->fresh()->cancellation_reason]);
        $this->assertSame(1, WhatsappMessage::query()->where('template_key', MessageTemplateKey::Cancelled)->where('appointment_id', $appointment->id)->count());
    }

    public function test_after_cancelling_the_patient_can_be_given_another_appointment_right_away(): void
    {
        $appointment = $this->book('2026-11-03 10:00');

        $this->page($appointment)
            ->mountAction('cancel')
            ->setActionData(['who' => CancelledBy::Patient->value])
            ->setActionData(['then' => 'rebook'])
            ->callMountedAction()
            ->assertRedirect(AppointmentResource::newUrl([
                'patient' => $appointment->patient_id,
                'doctor' => $this->doctor->id,
                'treatment' => $this->cleaning->id,
            ]));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_after_cancelling_the_patient_can_go_to_the_waitlist_once(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $first = $this->book('2026-11-03 10:00', $patient);
        $second = $this->book('2026-11-10 10:00', $patient);

        $cancel = fn (Appointment $appointment) => $this->page($appointment)
            ->mountAction('cancel')
            ->setActionData(['who' => CancelledBy::Clinic->value])
            ->setActionData(['then' => 'waitlist'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $cancel($first)->assertNotified('Rosa Quispe quedó en la lista de espera');

        $entry = WaitlistEntry::sole();
        $this->assertSame(
            [$patient->id, $this->cleaning->id, $this->doctor->id, '2026-11-02', WaitlistStatus::Waiting],
            [$entry->patient_id, $entry->treatment_id, $entry->doctor_id, $entry->earliest_date->toDateString(), $entry->status],
        );

        // Ya espera por lo mismo: no se le anota dos veces.
        $cancel($second)->assertNotified('Rosa Quispe ya estaba en la lista de espera');
        $this->assertSame(1, WaitlistEntry::count());
    }

    public function test_cancelling_one_of_a_series_offers_to_cancel_the_ones_that_follow(): void
    {
        $patient = Patient::factory()->create();
        [$first, $second] = app(AppointmentService::class)->bookSeries($this->reception, $patient, $this->doctor, $this->cleaning, CarbonImmutable::parse('2026-11-03 10:00'), 1, 3)['booked'];
        $alone = $this->book('2026-11-03 16:00');

        $this->page($alone)->mountAction('cancel')->assertFormFieldHidden('series');

        $this->page($second)
            ->mountAction('cancel')
            ->assertMountedActionModalSee('Cancelar también su cita siguiente de la serie');

        $this->page($first)
            ->mountAction('cancel')
            ->assertMountedActionModalSee('Cancelar también sus 2 citas siguientes de la serie')
            ->setActionData(['who' => CancelledBy::Patient->value])
            ->setActionData(['series' => true])
            ->callMountedAction()
            ->assertNotified('3 citas canceladas');

        $this->assertSame(3, Appointment::query()->whereBelongsTo($patient)->where('status', AppointmentStatus::Cancelled)->count());
        $this->assertSame(AppointmentStatus::Confirmed, $alone->fresh()->status);
    }

    private function page(Appointment $appointment): Testable
    {
        return Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()]);
    }

    private function book(string $startsAt, ?Patient $patient = null): Appointment
    {
        return app(AppointmentService::class)->bookFromStaff($this->reception, $patient ?? Patient::factory()->create(), $this->doctor, $this->cleaning, CarbonImmutable::parse($startsAt));
    }
}
