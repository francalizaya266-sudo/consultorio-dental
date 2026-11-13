<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Filament\Pages\Agenda;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** La semana de todo el consultorio (los doctores lado a lado en cada día) y la hoja del día para imprimir. */
class ClinicWeekAndPrintTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $ana;

    private Doctor $luis;

    private Appointment $lucia;

    private Appointment $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $this->ana = Doctor::factory()->withSchedule()->create(['name' => 'Dra. Ana Ríos', 'agenda_color' => '#0f766e', 'sort_order' => 1]);
        $this->luis = Doctor::factory()->withSchedule()->create(['name' => 'Dr. Luis Paz', 'agenda_color' => '#b45309', 'sort_order' => 2]);
        $this->ana->treatments()->attach($this->treatment);
        $this->luis->treatments()->attach($this->treatment);
        $this->lucia = $this->visit($this->ana, 'Lucía', '2026-11-03 10:00');
        $this->bruno = $this->visit($this->luis, 'Bruno', '2026-11-03 10:00');
        $this->visit($this->luis, 'Carla', '2026-11-04 16:00');
        $this->actingAs(User::factory()->reception()->create());
    }

    public function test_the_week_of_the_whole_clinic_puts_the_doctors_side_by_side_in_each_day(): void
    {
        $week = Livewire::test(Agenda::class)
            ->set('mode', 'week')
            ->assertSeeHtml('<option value="0">Todo el consultorio</option>')
            // La semana de un doctor: solo sus citas.
            ->set('doctor', $this->ana->id)
            ->assertSee('Lucía')
            ->assertDontSee(['Bruno', 'Carla'])
            ->set('doctor', 0)
            ->assertSee(['Lucía', 'Bruno', 'Carla'])
            // Arriba, de quién es cada color.
            ->assertSeeHtmlInOrder(['class="agenda-key"', '--doc: #0f766e', 'Dra. Ana Ríos', '--doc: #b45309', 'Dr. Luis Paz']);

        $html = $week->html();

        // Una columna por día de atención (lunes a sábado), no una por doctor.
        $this->assertSame(6, substr_count($html, 'class="agenda-col"'));
        // El martes cada doctor va en su carril, con su color.
        $this->assertSame(1, substr_count($html, '--lane: 0; --lanes: 2; --doc: #0f766e;'));
        $this->assertSame(2, substr_count($html, '--lane: 1; --lanes: 2; --doc: #b45309;'));
        // Cada día dice qué tan lleno está entre todos.
        $this->assertSame(6, substr_count($html, 'class="agenda-head-load'));
        $week->assertSeeHtmlInOrder(['>Mar.<', '>8 % · 2 citas<', '>Mié.<', '>4 % · 1 cita<', '>Jue.<', '>Sin citas<']);
    }

    public function test_the_whole_clinic_week_opens_from_its_address(): void
    {
        Livewire::withQueryParams(['mode' => 'week', 'doctor' => '0'])
            ->test(Agenda::class)
            ->assertSet('doctor', 0)
            ->assertSee(['Lucía', 'Bruno']);
    }

    public function test_a_doctor_only_sees_their_own_week(): void
    {
        $this->actingAs($this->ana->user);

        Livewire::test(Agenda::class)
            ->set('mode', 'week')
            ->set('doctor', 0)
            ->assertSet('doctor', $this->ana->id)
            ->assertSee('Lucía')
            ->assertDontSee('Bruno')
            ->assertDontSeeHtml('<option value="0">');
    }

    public function test_an_appointment_dragged_in_the_whole_clinic_week_stays_with_its_doctor(): void
    {
        Livewire::test(Agenda::class)
            ->set('mode', 'week')
            ->set('doctor', 0)
            ->mountAction('move', ['id' => $this->bruno->id, 'column' => '0|2026-11-05', 'time' => '16:00'])
            ->assertMountedActionModalSee('Bruno Prueba pasa del martes 3 de noviembre a las 10:00 al jueves 5 de noviembre a las 16:00.')
            ->setActionData(['agreed' => true])
            ->callMountedAction()
            ->assertNotified('Cita movida y confirmada.');

        $this->assertSame([$this->luis->id, '2026-11-05 16:00'], [$this->bruno->fresh()->doctor_id, $this->bruno->fresh()->starts_at->format('Y-m-d H:i')]);
    }

    public function test_from_the_whole_clinic_week_a_new_appointment_or_block_does_not_assume_a_doctor(): void
    {
        Livewire::test(Agenda::class)
            ->set('mode', 'week')
            ->set('doctor', 0)
            ->mountAction('newAppointment')
            ->assertActionDataSet(['doctor_id' => null])
            ->unmountAction()
            ->mountAction('blockSchedule')
            ->assertActionDataSet(['doctor_id' => null]);
    }

    public function test_the_day_sheet_lists_each_doctor_with_their_appointments_to_tick_by_hand(): void
    {
        $this->lucia->update(['internal_notes' => 'Viene con su mamá', 'is_new_patient' => true]);
        $this->visit($this->ana, 'Diego', '2026-11-03 16:00', ['status' => AppointmentStatus::Cancelled]);
        app(AppointmentService::class)->bookFromStaff(auth()->user(), Patient::factory()->create(['first_name' => 'Elena', 'last_name' => 'Prueba']), $this->luis, $this->treatment, CarbonImmutable::parse('2026-11-03 10:00'), ['overbook' => true]);
        ScheduleBlock::query()->create(['doctor_id' => $this->luis->id, 'type' => 'course', 'starts_at' => '2026-11-03 15:00', 'ends_at' => '2026-11-03 19:00', 'reason' => 'Curso de implantes']);

        Livewire::test(Agenda::class)
            ->set('date', '2026-11-03')
            ->assertActionHasUrl('printDay', Agenda::printUrl('2026-11-03'));

        $this->get(Agenda::printUrl('2026-11-03'))
            ->assertOk()
            ->assertSeeInOrder([
                'Agenda del día',
                'Martes 3 de noviembre de 2026 · 3 citas',
                'Dra. Ana Ríos',
                'Atiende de 09:00 a 13:00 y de 15:00 a 19:00',
                '10:00–10:30',
                'Lucía Prueba',
                '· Nuevo',
                'Limpieza dental',
                'Confirmada',
                'Viene con su mamá',
                'Dr. Luis Paz',
                'No se atiende de 15:00 a 19:00: Curso de implantes',
                'Bruno Prueba',
                'Elena Prueba',
                '· Sobrecupo',
            ])
            // La cancelada no va en la hoja.
            ->assertDontSee('Diego Prueba');
    }

    public function test_the_day_sheet_says_which_part_of_the_day_each_block_takes(): void
    {
        ScheduleBlock::query()->create(['doctor_id' => null, 'type' => 'holiday', 'starts_at' => '2026-11-04 00:00', 'ends_at' => '2026-11-05 00:00', 'reason' => 'Aniversario']);
        ScheduleBlock::query()->create(['doctor_id' => $this->ana->id, 'type' => 'vacation', 'starts_at' => '2026-11-05 15:00', 'ends_at' => '2026-11-07 13:00']);

        $this->get(Agenda::printUrl('2026-11-04'))->assertSee('No se atiende todo el día: Aniversario');
        $this->get(Agenda::printUrl('2026-11-05'))->assertSee('No se atiende desde las 15:00: Vacaciones');
        $this->get(Agenda::printUrl('2026-11-06'))->assertSee('No se atiende todo el día: Vacaciones');
        $this->get(Agenda::printUrl('2026-11-07'))->assertSee('No se atiende hasta las 13:00: Vacaciones');
    }

    public function test_the_day_sheet_of_a_doctor_only_has_their_own_agenda(): void
    {
        $this->actingAs($this->ana->user);

        $this->get(Agenda::printUrl('2026-11-03'))
            ->assertOk()
            ->assertSee(['Dra. Ana Ríos', 'Lucía Prueba', '1 cita'])
            ->assertDontSee(['Dr. Luis Paz', 'Bruno Prueba']);
    }

    public function test_the_day_sheet_defaults_to_today_and_says_when_nobody_works(): void
    {
        $this->get(route('filament.admin.agenda.print'))->assertOk()->assertSee('Lunes 2 de noviembre de 2026 · 0 citas');
        $this->get(Agenda::printUrl('2026-11-08'))->assertOk()->assertSee('Nadie atiende ni tiene citas este día.');
    }

    public function test_the_day_sheet_asks_for_a_real_date_and_a_signed_in_user(): void
    {
        $this->get(Agenda::printUrl('2026-13-45'))->assertInvalid(['date']);

        auth()->logout();

        $this->get(Agenda::printUrl('2026-11-03'))->assertRedirect();
    }

    /** @param  array<string, mixed>  $attributes */
    private function visit(Doctor $doctor, string $firstName, string $startsAt, array $attributes = []): Appointment
    {
        return Appointment::factory()
            ->for($doctor)
            ->for($this->treatment)
            ->for(Patient::factory()->state(['first_name' => $firstName, 'last_name' => 'Prueba']))
            ->confirmed()
            ->at($startsAt)
            ->create($attributes);
    }
}
