<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\ScheduleBlocks\Pages\ManageScheduleBlocks;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\Concerns\ReadsNotifications;
use Tests\TestCase;

/** Un bloqueo sobre citas ya dadas: el panel dice cuáles son y las deja a la vista hasta que se muevan. */
class ScheduleBlockClashTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase, ReadsNotifications;

    private Treatment $treatment;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->treatment);
    }

    public function test_a_new_block_names_the_appointments_inside_and_links_to_them(): void
    {
        $this->visit('Lucía', '2026-11-10 10:00');
        $this->visit('Bruno', '2026-11-10 16:00');
        $this->visit('Carla', '2026-11-11 10:00');
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', [
                'doctor_id' => $this->doctor->id,
                'type' => 'vacation',
                'all_day' => false,
                'starts_at' => '2026-11-10 08:00',
                'ends_at' => '2026-11-10 19:00',
            ])
            ->assertHasNoFormErrors();

        $notice = $this->shownNotifications()->firstWhere('title', 'Hay 2 citas en ese horario');
        $this->assertSame(
            'Lucía Prueba (mar. 10, 10:00), Bruno Prueba (mar. 10, 16:00). El bloqueo no las mueve: cámbialas de horario para avisar a los pacientes.',
            $notice['body'],
        );
        $this->assertSame(
            [['label' => 'Ver esas citas', 'url' => ManageScheduleBlocks::appointmentsInsideUrl()]],
            array_map(fn (array $action) => ['label' => $action['label'], 'url' => $action['url']], $notice['actions']),
        );
    }

    public function test_a_long_list_of_appointments_inside_is_cut_after_three_names(): void
    {
        foreach (['Lucía' => '09:00', 'Bruno' => '10:00', 'Carla' => '11:00', 'Diego' => '12:00', 'Elena' => '15:00'] as $name => $time) {
            $this->visit($name, "2026-11-10 {$time}");
        }
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', ['type' => 'holiday', 'all_day' => false, 'starts_at' => '2026-11-10 08:00', 'ends_at' => '2026-11-10 19:00'])
            ->assertHasNoFormErrors();

        $notice = $this->shownNotifications()->firstWhere('title', 'Hay 5 citas en ese horario');
        $this->assertStringStartsWith('Lucía Prueba (mar. 10, 09:00), Bruno Prueba (mar. 10, 10:00), Carla Prueba (mar. 10, 11:00) y 2 más. ', $notice['body']);
    }

    public function test_the_appointments_list_can_show_only_the_ones_inside_a_block(): void
    {
        $inside = $this->visit('Lucía', '2026-11-10 10:00');
        $clinicWide = $this->visit('Bruno', '2026-11-12 10:00');
        $outside = $this->visit('Carla', '2026-11-11 10:00');
        $otherDoctor = Appointment::factory()->for($this->doctorOffering($this->treatment))->for($this->treatment)->at('2026-11-10 10:00')->create();
        $this->block('2026-11-10 08:00', '2026-11-10 19:00', $this->doctor);
        $this->block('2026-11-12 08:00', '2026-11-12 19:00');
        $this->actingAs(User::factory()->reception()->create());

        $this->get(ManageScheduleBlocks::appointmentsInsideUrl())->assertOk();

        Livewire::test(ListAppointments::class, ['activeTab' => 'upcoming'])
            ->assertCanSeeTableRecords([$inside, $clinicWide, $outside, $otherDoctor])
            ->filterTable('in_block')
            ->assertCanSeeTableRecords([$inside, $clinicWide])
            ->assertCanNotSeeTableRecords([$outside, $otherDoctor]);
    }

    public function test_the_blocks_table_counts_the_appointments_still_inside_each_block(): void
    {
        $this->visit('Lucía', '2026-11-10 10:00');
        $this->visit('Bruno', '2026-11-10 16:00');
        $this->visit('Carla', '2026-11-10 17:00', ['status' => AppointmentStatus::Cancelled]);
        $this->visit('Diego', '2026-11-12 10:00');
        $this->block('2026-11-10 08:00', '2026-11-10 19:00', $this->doctor);
        $this->block('2026-11-12 08:00', '2026-11-12 19:00');
        $empty = $this->block('2026-11-13 08:00', '2026-11-13 19:00', $this->doctor);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageScheduleBlocks::class)
            ->assertSeeInOrder(['2 citas por mover', '1 cita por mover'])
            ->assertTableColumnStateSet('appointments_inside', null, $empty)
            ->assertSeeHtml('href="'.e(ManageScheduleBlocks::appointmentsInsideUrl()).'"');
    }

    public function test_a_doctor_only_counts_their_own_appointments_inside_a_clinic_block(): void
    {
        $this->visit('Lucía', '2026-11-12 10:00');
        Appointment::factory()->for($this->doctorOffering($this->treatment))->for($this->treatment)->at('2026-11-12 10:00')->create();
        $this->block('2026-11-12 08:00', '2026-11-12 19:00');
        $this->actingAs($this->doctor->user);

        Livewire::test(ManageScheduleBlocks::class)
            ->assertSee('1 cita por mover')
            ->assertDontSee('2 citas por mover');
    }

    public function test_the_agenda_marks_the_appointments_inside_a_block_in_the_day_the_list_and_the_panel(): void
    {
        $inside = $this->visit('Lucía', '2026-11-03 10:00');
        $free = $this->visit('Bruno', '2026-11-03 16:00');
        $this->block('2026-11-03 09:00', '2026-11-03 13:00', $this->doctor, 'Congreso');
        $this->actingAs(User::factory()->reception()->create());

        $agenda = Livewire::test(Agenda::class, ['date' => '2026-11-03']);
        $this->assertSame(1, substr_count($agenda->html(), 'class="tag tag-block"'));
        $agenda->assertSeeHtmlInOrder(['Lucía', 'En bloqueo', 'Bruno']);

        $agenda->set('mode', 'list');
        $this->assertSame(1, substr_count($agenda->html(), 'class="tag tag-block"'));

        $agenda->mountAction(TestAction::make('appointment')->arguments(['id' => $inside->id]))
            ->assertMountedActionModalSee(['En bloqueo: muévela', 'Congreso'])
            ->unmountAction()
            ->mountAction(TestAction::make('appointment')->arguments(['id' => $free->id]))
            ->assertMountedActionModalDontSee('En bloqueo: muévela');
    }

    public function test_a_visit_that_already_ended_or_was_cancelled_is_not_marked_as_inside_a_block(): void
    {
        $this->visit('Lucía', '2026-11-02 09:00');
        $this->visit('Bruno', '2026-11-02 16:00', ['status' => AppointmentStatus::Cancelled]);
        $this->block('2026-11-02 09:00', '2026-11-02 19:00', $this->doctor);
        $this->travelTo('2026-11-02 11:00');
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->set('mode', 'list')
            ->assertSee('Lucía')
            ->assertDontSeeHtml('class="tag tag-block"');
    }

    public function test_a_new_block_that_already_ended_is_rejected_but_an_old_one_can_still_be_corrected(): void
    {
        $old = $this->block('2026-10-26 08:00', '2026-10-26 19:00', $this->doctor);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', ['type' => 'course', 'all_day' => false, 'starts_at' => '2026-10-27 09:00', 'ends_at' => '2026-10-27 13:00'])
            ->assertHasFormErrors(['ends_at' => 'Ese horario ya pasó. Revisa la fecha, porque un bloqueo solo sirve de ahora en adelante.']);

        $this->assertSame(1, ScheduleBlock::query()->count());

        // Empezó antes y sigue vigente: todavía bloquea lo que queda.
        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', ['type' => 'course', 'all_day' => false, 'starts_at' => '2026-11-02 07:00', 'ends_at' => '2026-11-02 13:00'])
            ->assertHasNoFormErrors();

        Livewire::test(ManageScheduleBlocks::class)
            ->removeTableFilter('upcoming')
            ->callAction(TestAction::make('edit')->table($old), ['reason' => 'Curso de implantes'])
            ->assertHasNoFormErrors();

        $this->assertSame('Curso de implantes', $old->fresh()->reason);
    }

    /** @param  array<string, mixed>  $attributes */
    private function visit(string $firstName, string $startsAt, array $attributes = []): Appointment
    {
        return Appointment::factory()
            ->for($this->doctor)
            ->for($this->treatment)
            ->for(Patient::factory()->state(['first_name' => $firstName, 'last_name' => 'Prueba']))
            ->confirmed()
            ->at($startsAt)
            ->create($attributes);
    }

    private function block(string $from, string $to, ?Doctor $doctor = null, ?string $reason = null): ScheduleBlock
    {
        return ScheduleBlock::query()->create(['doctor_id' => $doctor?->id, 'type' => 'course', 'starts_at' => $from, 'ends_at' => $to, 'reason' => $reason]);
    }
}
