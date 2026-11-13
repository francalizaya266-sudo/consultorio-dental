<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Exceptions\BookingException;
use App\Filament\Pages\Agenda;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
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
            ->assertNotified('Cita movida. El aviso al paciente está listo en Avisos.');

        $appointment->refresh();
        $this->assertSame('2026-11-04 16:30', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertTrue($appointment->doctor->is($ramos));
    }

    public function test_moving_says_from_when_to_when_and_warns_outside_working_hours(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-04 10:30')->confirmed()->create();
        $this->actingAs(User::factory()->reception()->create());

        // Dentro del horario: de dónde a dónde, sin advertencia.
        Livewire::test(Agenda::class, ['date' => '2026-11-04'])
            ->mountAction('move', arguments: ['id' => $appointment->id, 'column' => "{$doctor->id}|2026-11-04", 'time' => '11:00'])
            ->assertMountedActionModalSee(['pasa del miércoles 4 de noviembre a las 10:30 al miércoles 4 de noviembre a las 11:00', 'Sí, mover'])
            ->assertMountedActionModalDontSee('fuera de la atención');

        // En el almuerzo: lo dice, con el horario del doctor, y pide "Mover igual".
        Livewire::test(Agenda::class, ['date' => '2026-11-04'])
            ->mountAction('move', arguments: ['id' => $appointment->id, 'column' => "{$doctor->id}|2026-11-04", 'time' => '13:30'])
            ->assertMountedActionModalSee(['¿Mover la cita fuera del horario?', 'de 09:00 a 13:00 y de 15:00 a 19:00', 'Mover igual']);
    }

    public function test_a_move_already_agreed_with_the_patient_stays_confirmed_without_a_notice(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-04 10:30')->confirmed()->create();
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class, ['date' => '2026-11-04'])
            ->callAction('move', ['agreed' => true], arguments: ['id' => $appointment->id, 'column' => "{$doctor->id}|2026-11-04", 'time' => '16:00'])
            ->assertNotified('Cita movida y confirmada.');

        $appointment->refresh();
        $this->assertSame('16:00', $appointment->starts_at->format('H:i'));
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(0, WhatsappMessage::where('template_key', MessageTemplateKey::Rescheduled)->count());
    }

    public function test_appointments_at_the_same_time_sit_side_by_side(): void
    {
        $cards = Agenda::inLanes(collect([
            ['id' => 1, 'start' => 600, 'end' => 660],
            ['id' => 2, 'start' => 600, 'end' => 630],
            ['id' => 3, 'start' => 630, 'end' => 660],
            ['id' => 4, 'start' => 700, 'end' => 730],
        ]));

        $this->assertSame(
            [1 => [0, 2], 2 => [1, 2], 3 => [1, 2], 4 => [0, 1]],
            $cards->mapWithKeys(fn (array $c) => [$c['id'] => [$c['lane'], $c['lanes']]])->all(),
        );
    }

    public function test_dropping_on_a_taken_time_explains_why(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-04 10:00')->create();
        $inTheWay = Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']))->at('2026-11-04 16:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        // El aviso dice con quién choca y a qué hora, para no tener que buscarlo en la agenda.
        Livewire::test(Agenda::class, ['date' => '2026-11-04'])
            ->callAction('move', arguments: ['id' => $appointment->id, 'column' => "{$doctor->id}|2026-11-04", 'time' => '16:15'])
            ->assertNotified('Ese horario se cruza con la cita de Rosa Quispe, de 16:00 a 16:30'.($inTheWay->buffer_minutes > 0 ? " (más {$inTheWay->buffer_minutes} min de esterilización)" : '').'. Elige otro.');

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

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $doctor->id, 'date' => '2026-11-03', 'time' => '10:07'])
            ->assertActionDataSet(['doctor_id' => $doctor->id, 'date' => '2026-11-03'])
            ->assertMountedActionModalSee('queda puesta la primera hora libre desde las 10:07, la que tocaste en la agenda')
            ->setActionData(['treatment_id' => $treatment->id])
            ->assertActionDataSet(['slot' => '10:15']);
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
            ->assertNotified('Cita creada. El aviso al paciente está listo en Avisos.');

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

    public function test_how_they_found_us_is_asked_only_the_first_time(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $regular = Patient::factory()->create(['first_visit_at' => now()->subYear(), 'origin' => null]);
        $registeredButNeverCame = Patient::factory()->create(['first_visit_at' => null, 'origin' => null]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $doctor->id, 'date' => '2026-11-03', 'time' => '16:00'])
            ->assertFormFieldHidden('origin')
            ->setActionData(['patient_id' => $regular->id])
            ->assertFormFieldHidden('origin')
            ->setActionData(['patient_id' => $registeredButNeverCame->id])
            ->assertFormFieldVisible('origin')
            ->setActionData(['patient_id' => null, 'new_patient' => true])
            ->assertFormFieldVisible('origin');
    }

    public function test_a_new_patient_is_not_left_behind_when_the_time_was_taken(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        // Un bloqueo: encima de otra cita la ventana ya frena antes y ofrece el sobrecupo.
        $inTheWay = ScheduleBlock::query()->create(['doctor_id' => $doctor->id, 'type' => 'course', 'starts_at' => '2026-11-03 15:00', 'ends_at' => '2026-11-03 19:00']);

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
            ->assertNotified(BookingException::overlaps($inTheWay)->getMessage());

        $this->assertFalse(Patient::query()->where(['first_name' => 'Ana', 'last_name' => 'Rojas'])->exists());
    }

    public function test_an_appointment_past_its_time_and_not_closed_says_so_on_the_card_and_in_the_panel(): void
    {
        $this->freezeMonday();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00', 'America/Lima'));
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $forgotten = Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-02 10:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->confirmed()->at('2026-11-02 15:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 09:00')->create(['status' => AppointmentStatus::Completed]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSeeHtml('agenda-appt s-confirmed')
            ->assertDontSeeHtml('border-left-color')
            ->mountAction(TestAction::make('appointment')->arguments(['id' => $forgotten->id]))
            ->assertMountedActionModalSee('Sin cerrar: marca si vino');

        // Solo la de las 10:00: la de la tarde aún no llega y la atendida ya se cerró.
        $this->assertSame(1, substr_count(Livewire::test(Agenda::class)->html(), 'class="tag tag-unclosed"'));
    }

    public function test_the_heading_is_the_day_shown_and_the_week_uses_short_names(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create(['first_name' => 'Benjamín Andrés', 'last_name' => 'Del Valle Salinas']);
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-11-04 10:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSee('Lunes 2 de noviembre')
            ->set('mode', 'week')
            ->assertSee('Semana del 2 de noviembre')
            ->assertSeeHtml('<b>Benjamín Del Valle<')
            ->assertSee('10:00 · '.$treatment->name);
    }

    public function test_new_appointment_from_the_header_comes_with_the_day_being_viewed(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $this->doctorOffering($treatment);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class, ['date' => '2026-11-05'])
            ->assertActionVisible('newAppointment')
            ->mountAction('newAppointment')
            ->assertActionDataSet(['date' => '2026-11-05', 'doctor_id' => null]);

        Livewire::withQueryParams(['date' => '2026-11-05', 'mode' => 'week', 'doctor' => $doctor->id])
            ->test(Agenda::class)
            ->mountAction('newAppointment')
            ->assertActionDataSet(['date' => '2026-11-05', 'doctor_id' => $doctor->id]);
    }

    public function test_new_appointment_says_when_the_patient_already_has_one_coming(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $other = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create();
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-11-02 16:00')->cancelled()->create();
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-11-03 10:00')->confirmed()->create();

        $this->actingAs(User::factory()->reception()->create());

        // Para no agendarle dos veces lo mismo: bajo su nombre sale la cita que le falta (la cancelada no cuenta).
        Livewire::test(Agenda::class)
            ->mountAction('newAppointment')
            ->assertMountedActionModalDontSee('Ya tiene cita')
            ->setActionData(['patient_id' => $patient->id])
            ->assertMountedActionModalSee('Ya tiene cita mañana a las 10:00 · Limpieza dental.');

        // El doctor solo se entera de las de su propia agenda.
        $this->actingAs($other->user);

        Livewire::test(Agenda::class)
            ->mountAction('newAppointment')
            ->setActionData(['patient_id' => $patient->id])
            ->assertMountedActionModalDontSee('Ya tiene cita');
    }

    public function test_the_legend_names_every_colour_drawn_and_the_red_dot_of_the_week(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 07:00')->create(['status' => AppointmentStatus::NoShow]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSeeHtml('s-no_show')
            ->assertSeeInOrder(['Pendiente', 'Confirmada', 'En sala', 'En atención', 'Atendida', 'No asistió', 'No se atiende', 'Punto rojo: sin cerrar, llamar o en bloqueo']);
    }

    public function test_with_a_single_doctor_the_doctor_is_already_chosen(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment')
            ->assertActionDataSet(['doctor_id' => $doctor->id, 'date' => '2026-11-02']);
    }

    public function test_the_week_shows_each_day_with_its_number_and_how_many_appointments_it_has(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory())->at('2026-11-03 10:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory())->at('2026-11-03 11:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::withQueryParams(['mode' => 'week', 'doctor' => $doctor->id])
            ->test(Agenda::class)
            ->assertSeeHtml('agenda-head is-week is-today')
            ->assertSeeInOrder(['Lun.', 'Sin citas', 'Mar.', '2 citas', 'Mié.', 'Sin citas']);
    }

    public function test_the_day_shows_the_doctor_with_initials_and_how_many_appointments_they_have(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $doctor->update(['name' => 'Dra. Ana María Torres Ruiz']);
        Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory())->at('2026-11-02 10:00')->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSeeHtml('aria-hidden="true">AT</span>')
            ->assertSee('1 cita');
    }

    public function test_the_current_time_is_marked_on_today_and_hides_the_hour_it_covers(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:05', 'America/Lima'));
        $this->doctorOffering(Treatment::factory()->create());

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSeeHtml('class="agenda-now-label"')
            ->assertSeeHtml('class="is-hidden"')
            ->assertSee('10:05');

        Livewire::test(Agenda::class, ['date' => '2026-11-03'])
            ->assertDontSeeHtml('agenda-now-label');
    }

    public function test_the_grid_knows_the_free_time_for_the_new_appointment_preview(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        ScheduleBlock::query()->create(['doctor_id' => $doctor->id, 'type' => 'course', 'starts_at' => '2026-11-02 16:00', 'ends_at' => '2026-11-02 17:00', 'reason' => 'Curso']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSeeHtml('data-working="[[540,780],[900,1140]]"')
            ->assertSeeHtml('data-busy="[[960,1020]]"');
    }
}
