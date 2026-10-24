<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingException;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class AgendaCalendarTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_reception_sees_every_doctor_on_the_day(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $torres = $this->doctorOffering($treatment);
        $ramos = $this->doctorOffering($treatment);
        Appointment::factory()->for($torres)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Lucía']))->at('2026-11-02 10:00')->create();
        Appointment::factory()->for($ramos)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Bruno']))->at('2026-11-02 11:00')->create();
        ScheduleBlock::query()->create(['doctor_id' => $ramos->id, 'type' => 'course', 'starts_at' => '2026-11-02 15:00', 'ends_at' => '2026-11-02 19:00', 'reason' => 'Congreso']);

        $this->actingAs(User::factory()->reception()->create());

        $this->get(Agenda::getUrl())->assertOk();

        Livewire::test(Agenda::class)
            ->assertSee([$torres->name, $ramos->name, 'Lucía', 'Bruno', 'Congreso']);
    }

    public function test_a_doctor_only_sees_their_own_agenda_and_starts_on_the_week(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $mine = $this->doctorOffering($treatment);
        $other = $this->doctorOffering($treatment);
        Appointment::factory()->for($mine)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Lucía']))->at('2026-11-04 10:00')->create();
        Appointment::factory()->for($other)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Bruno']))->at('2026-11-04 10:00')->create();

        $this->actingAs($mine->user);

        Livewire::test(Agenda::class)
            ->assertSet('mode', 'week')
            ->assertSet('doctor', $mine->id)
            ->assertSee('Lucía')
            ->assertDontSee('Bruno')
            ->set('doctor', $other->id)
            ->assertSet('doctor', $mine->id)
            ->assertDontSee('Bruno');
    }

    public function test_dropping_an_appointment_moves_it_after_confirming(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $torres = $this->doctorOffering($treatment);
        $ramos = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($torres)->for($treatment)->at('2026-11-04 10:00')->confirmed()->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class, ['date' => '2026-11-04'])
            ->callAction('move', arguments: ['id' => $appointment->id, 'column' => "{$ramos->id}|2026-11-04", 'time' => '16:30'])
            ->assertNotified('Cita movida. El aviso al paciente está listo en "Avisos por enviar".');

        $appointment->refresh();
        $this->assertSame('2026-11-04 16:30', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertTrue($appointment->doctor->is($ramos));
    }

    public function test_dropping_on_a_taken_time_explains_why(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-04 10:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-04 16:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class, ['date' => '2026-11-04'])
            ->callAction('move', arguments: ['id' => $appointment->id, 'column' => "{$doctor->id}|2026-11-04", 'time' => '16:15'])
            ->assertNotified(BookingException::overlaps()->getMessage());

        $this->assertSame('10:00', $appointment->fresh()->starts_at->format('H:i'));
    }

    public function test_a_doctor_cannot_move_someone_elses_appointment(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $mine = $this->doctorOffering($treatment);
        $other = $this->doctorOffering($treatment);
        $foreign = Appointment::factory()->for($other)->for($treatment)->at('2026-11-04 10:00')->create();

        $this->actingAs($mine->user);

        Livewire::test(Agenda::class)
            ->callAction('move', arguments: ['id' => $foreign->id, 'column' => "{$mine->id}|2026-11-04", 'time' => '16:00']);

        $this->assertTrue($foreign->fresh()->doctor->is($other));
        $this->assertSame('10:00', $foreign->fresh()->starts_at->format('H:i'));
    }

    public function test_staff_cannot_move_an_appointment_to_a_doctor_who_does_not_do_that_treatment(): void
    {
        $this->freezeMonday();
        $cleaning = Treatment::factory()->create();
        $braces = Treatment::factory()->create();
        $torres = $this->doctorOffering($cleaning);
        $ramos = $this->doctorOffering($braces);
        $appointment = Appointment::factory()->for($torres)->for($cleaning)->at('2026-11-04 10:00')->create();

        $this->expectExceptionObject(BookingException::doctorDoesNotOfferTreatment());

        app(AppointmentService::class)->rescheduleByStaff($appointment, CarbonImmutable::parse('2026-11-04 16:00'), User::factory()->reception()->create(), $ramos);
    }

    public function test_tapping_a_free_space_suggests_the_nearest_free_time(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::withQueryParams(['doctor' => $doctor->id, 'date' => '2026-11-03', 'time' => '10:07'])
            ->test(CreateAppointment::class)
            ->assertSchemaStateSet(['doctor_id' => $doctor->id, 'date' => '2026-11-03'])
            ->fillForm(['treatment_id' => $treatment->id])
            ->assertSchemaStateSet(['slot' => '10:15']);
    }

    public function test_tapping_an_appointment_opens_a_side_panel_with_balance_and_the_day_buttons(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza']);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create(['first_name' => 'María', 'last_name' => 'Quispe']);
        Charge::factory()->for($patient)->create(['amount' => 40]);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-11-02 10:00')->create(['internal_notes' => 'Trae sus radiografías']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSee(['Debe S/ 40', 'Nota'])
            ->mountAction(TestAction::make('appointment')->arguments(['id' => $appointment->id]))
            ->assertMountedActionModalSee(['María Quispe', 'Limpieza', 'Debe S/ 40', 'Trae sus radiografías'])
            ->callAction('confirm')
            ->assertNotified('Cita confirmada')
            ->assertActionMounted('appointment');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_reception_does_not_see_medical_alerts_in_the_panel_and_a_doctor_cannot_open_another_doctors_appointment(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $mine = $this->doctorOffering($treatment);
        $other = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create();
        $patient->medicalBackground()->create(['allergies' => 'Penicilina']);
        $appointment = Appointment::factory()->for($mine)->for($treatment)->for($patient)->at('2026-11-02 10:00')->create();
        $foreign = Appointment::factory()->for($other)->for($treatment)->at('2026-11-02 11:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction(TestAction::make('appointment')->arguments(['id' => $appointment->id]))
            ->assertMountedActionModalDontSee('Penicilina');

        $this->actingAs($mine->user);

        Livewire::test(Agenda::class)
            ->mountAction(TestAction::make('appointment')->arguments(['id' => $appointment->id]))
            ->assertMountedActionModalSee('Alergia: Penicilina')
            ->assertActionNotMounted(TestAction::make('appointment')->arguments(['id' => $foreign->id]));

        Livewire::test(Agenda::class)
            ->mountAction(TestAction::make('appointment')->arguments(['id' => $foreign->id]))
            ->assertActionNotMounted('appointment');
    }

    public function test_tapping_a_free_space_opens_new_appointment_over_the_agenda_with_doctor_and_time_set(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $this->doctorOffering($treatment);
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $doctor->id, 'date' => '2026-11-03', 'time' => '10:07'])
            ->assertActionDataSet(['doctor_id' => $doctor->id, 'date' => '2026-11-03'])
            ->setActionData(['patient_id' => $patient->id, 'treatment_id' => $treatment->id])
            ->assertActionDataSet(['slot' => '10:15'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Cita creada. El aviso al paciente está listo en "Avisos por enviar".');

        $appointment = $patient->appointments()->sole();
        $this->assertSame('2026-11-03 10:15', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertTrue($appointment->doctor->is($doctor));
    }

    public function test_a_new_patient_is_registered_in_the_same_window_as_the_appointment(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $doctor->id, 'date' => '2026-11-03', 'time' => '16:00'])
            ->setActionData(['new_patient' => true])
            ->setActionData([
                'patient' => ['first_name' => 'Ana', 'last_name' => 'Rojas', 'phone' => '987 654 321'],
                'treatment_id' => $treatment->id,
            ])
            ->assertActionDataSet(['slot' => '16:00'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $patient = Patient::query()->where('first_name', 'Ana')->sole();
        $this->assertSame('+51987654321', $patient->account->phone);
        $this->assertSame('2026-11-03 16:00', $patient->appointments()->sole()->starts_at->format('Y-m-d H:i'));
    }

    public function test_a_new_patient_is_not_left_behind_when_the_time_was_taken(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 16:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $doctor->id, 'date' => '2026-11-03'])
            ->setActionData(['new_patient' => true])
            ->setActionData([
                'patient' => ['first_name' => 'Ana', 'last_name' => 'Rojas'],
                'treatment_id' => $treatment->id,
                'manual' => true,
                'manual_time' => '16:00',
            ])
            ->callMountedAction()
            ->assertNotified(BookingException::overlaps()->getMessage());

        $this->assertFalse(Patient::query()->where('first_name', 'Ana')->exists());
    }

    public function test_with_a_single_doctor_the_doctor_is_already_chosen(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(CreateAppointment::class)
            ->assertSchemaStateSet(['doctor_id' => $doctor->id]);

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment')
            ->assertActionDataSet(['doctor_id' => $doctor->id, 'date' => '2026-11-02']);
    }
}
