<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** En "Nueva cita": el sobrecupo con su advertencia, repetir la cita, la duración a mano y no avisar al paciente. */
class OverbookAndRepeatWindowTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    private Patient $patient;

    private Appointment $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30, 'price_from' => 80]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->patient = Patient::factory()->create();
        $this->rosa = Appointment::factory()
            ->for($this->doctor)
            ->for($this->treatment)
            ->for(Patient::factory()->state(['first_name' => 'Rosa', 'last_name' => 'Quispe']))
            ->confirmed()
            ->at('2026-11-03 10:00')
            ->create();
        $this->actingAs(User::factory()->reception()->create());
    }

    public function test_another_time_over_an_appointment_must_be_marked_as_an_overbooking_to_pass(): void
    {
        // Con más citas ese día, la advertencia igual nombra a quien tiene esa hora.
        Appointment::factory()->for($this->doctor)->for($this->treatment)->confirmed()->at('2026-11-03 09:00')->create();

        $this->window('2026-11-03')
            ->assertFormFieldHidden('overbook')
            ->setActionData(['manual' => true])
            // En una hora libre no hay nada que advertir.
            ->setActionData(['manual_time' => '16:00'])
            ->assertFormFieldHidden('overbook')
            ->setActionData(['manual_time' => '10:15'])
            ->assertFormFieldVisible('overbook')
            ->assertMountedActionModalSee([
                'Sobrecupo: agendar igual, encima de la cita de Rosa Quispe',
                'Rosa Quispe ya tiene esa hora (de 10:00 a 10:30)',
            ])
            ->callMountedAction()
            ->assertHasFormErrors(['overbook' => 'Esa hora se cruza con otra cita. Elige otra hora, o marca "Sobrecupo" si el doctor aceptó verlo igual.'])
            ->setActionData(['overbook' => true])
            ->callMountedAction()
            ->assertNotified('Sobrecupo agendado. El aviso al paciente está listo en Avisos.');

        $urgent = Appointment::query()->whereBelongsTo($this->patient)->sole();
        $this->assertSame(['10:15', true], [$urgent->starts_at->format('H:i'), $urgent->is_overbooked]);
    }

    public function test_there_is_no_overbooking_over_a_blocked_time(): void
    {
        ScheduleBlock::query()->create(['doctor_id' => $this->doctor->id, 'type' => 'course', 'starts_at' => '2026-11-04 15:00', 'ends_at' => '2026-11-04 19:00']);

        $this->window('2026-11-04')
            ->setActionData(['manual' => true])
            ->setActionData(['manual_time' => '16:00'])
            ->assertFormFieldHidden('overbook');
    }

    public function test_an_overbooking_shows_its_mark_in_the_agenda_the_list_and_the_panel(): void
    {
        $urgent = app(AppointmentService::class)->bookFromStaff(auth()->user(), $this->patient, $this->doctor, $this->treatment, CarbonImmutable::parse('2026-11-03 10:00'), ['overbook' => true]);

        $agenda = Livewire::test(Agenda::class)->set('date', '2026-11-03');
        // Las dos a la misma hora, lado a lado, y solo la urgencia lleva la marca.
        $this->assertSame([1, 2], [substr_count($agenda->html(), 'class="tag tag-over"'), substr_count($agenda->html(), '--lanes: 2;')]);

        $agenda->set('mode', 'list');
        $this->assertSame(1, substr_count($agenda->html(), 'class="tag tag-over"'));

        $agenda->mountAction('appointment', ['id' => $urgent->id])->assertMountedActionModalSee('Sobrecupo');
        $agenda->unmountAction()->mountAction('appointment', ['id' => $this->rosa->id])->assertMountedActionModalDontSee('Sobrecupo');

        Livewire::test(ListAppointments::class)->assertSee('Sobrecupo');
    }

    public function test_repeating_previews_the_dates_and_books_them_all_with_one_notice(): void
    {
        // El 11 esa hora ya está tomada y el 18 es feriado.
        Appointment::factory()->for($this->doctor)->for($this->treatment)->confirmed()->at('2026-11-11 16:00')->create();
        ScheduleBlock::query()->create(['doctor_id' => null, 'type' => 'holiday', 'starts_at' => '2026-11-18 00:00', 'ends_at' => '2026-11-19 00:00']);

        $this->window('2026-11-04')
            ->setActionData(['slot' => '16:00'])
            ->assertFormFieldHidden('repeat_every')
            ->setActionData(['repeat' => true])
            ->assertFormFieldVisible('repeat_every')
            ->assertActionDataSet(['repeat_every' => 4, 'repeat_times' => 6])
            ->setActionData(['repeat_every' => 1, 'repeat_times' => 4])
            ->assertMountedActionModalSee([
                'Además de esta, quedan: mié. 11 nov. 16:40 (las 16:00 están ocupadas) · mié. 25 nov. 16:00.',
                'Sin lugar ese día, se saltan: mié. 18 nov.',
                'Al paciente se le prepara un solo aviso, el de la primera.',
            ])
            ->callMountedAction()
            ->assertNotified('3 citas creadas, una cada semana. El aviso de la primera está listo en Avisos. Sin lugar, no se agendó: mié. 18 nov.');

        $series = Appointment::query()->whereBelongsTo($this->patient)->orderBy('starts_at')->get();

        $this->assertSame(['2026-11-04 16:00', '2026-11-11 16:40', '2026-11-25 16:00'], $series->map(fn (Appointment $appointment) => $appointment->starts_at->format('Y-m-d H:i'))->all());
        $this->assertCount(1, $series->pluck('series_id')->filter()->unique());
        $this->assertSame([$series->first()->id], WhatsappMessage::query()->whereIn('appointment_id', $series->modelKeys())->pluck('appointment_id')->all());
    }

    public function test_one_of_a_series_says_which_one_it_is(): void
    {
        [, $second] = app(AppointmentService::class)->bookSeries(auth()->user(), $this->patient, $this->doctor, $this->treatment, CarbonImmutable::parse('2026-11-04 16:00'), 1, 3)['booked'];

        Livewire::test(Agenda::class)
            ->mountAction('appointment', ['id' => $second->id])
            ->assertMountedActionModalSee('Cita 2 de 3 de su serie')
            ->unmountAction()
            ->mountAction('appointment', ['id' => $this->rosa->id])
            ->assertMountedActionModalDontSee('de su serie');
    }

    public function test_a_duration_set_by_hand_recalculates_the_free_times_and_stays_with_the_appointment(): void
    {
        $this->window('2026-11-03')
            ->setActionData(['slot' => '09:00'])
            // Una hora ya no cabe antes de la cita de las 10:00: la hora elegida se suelta.
            ->setActionData(['duration_minutes' => 60])
            ->assertActionDataSet(['slot' => null])
            ->setActionData(['slot' => '16:00'])
            ->assertMountedActionModalSee("Dura 1\u{00A0}h: de 16:00 a 17:00")
            ->callMountedAction()
            ->assertNotified('Cita creada. El aviso al paciente está listo en Avisos.');

        $appointment = Appointment::query()->whereBelongsTo($this->patient)->sole();
        $this->assertSame(['16:00', '17:00', true], [$appointment->starts_at->format('H:i'), $appointment->ends_at->format('H:i'), $appointment->custom_duration]);
    }

    public function test_the_notice_can_be_left_out_when_the_patient_already_knows(): void
    {
        $this->window('2026-11-04')
            ->assertActionDataSet(['notify' => true])
            ->setActionData(['slot' => '16:00', 'notify' => false])
            ->callMountedAction()
            ->assertNotified('Cita creada. No se preparó aviso al paciente.');

        $this->assertSame(0, WhatsappMessage::query()->where('appointment_id', Appointment::query()->whereBelongsTo($this->patient)->sole()->id)->count());
    }

    /** "Nueva cita" abierta sobre ese día, con el paciente y el tratamiento ya elegidos. */
    private function window(string $date): Testable
    {
        return Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $this->doctor->id, 'date' => $date])
            ->setActionData(['patient_id' => $this->patient->id, 'treatment_id' => $this->treatment->id]);
    }
}
