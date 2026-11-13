<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\ScheduleBlockType;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\ScheduleBlocks\Pages\ManageScheduleBlocks;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\MedicalBackground;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Agenda\AvailabilityService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\Concerns\ReadsNotifications;
use Tests\TestCase;

/** Lo que ahora se hace sin salir de la agenda (llegó sin cita, bloquear un horario) y los bloqueos de día completo, repetidos y de feriados. */
class AgendaToolsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase, ReadsNotifications;

    private User $reception;

    private Treatment $treatment;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->reception = User::factory()->reception()->create();
        $this->actingAs($this->reception);
    }

    public function test_who_comes_without_an_appointment_is_received_from_the_agenda(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        MedicalBackground::create(['patient_id' => $rosa->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:07', 'America/Lima'));

        Livewire::test(Agenda::class)
            ->assertActionVisible('walkIn')
            ->mountAction('walkIn')
            ->setActionData(['patient_id' => $rosa->id])
            ->setActionData(['treatment_id' => $this->treatment->id])
            ->callMountedAction()
            ->assertNotified('Paciente en sala');

        $appointment = Appointment::query()->whereBelongsTo($rosa)->sole();

        $this->assertSame(['2026-11-02 10:15', AppointmentStatus::CheckedIn], [$appointment->starts_at->format('Y-m-d H:i'), $appointment->status]);
        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_a_block_made_from_the_agenda_comes_as_the_whole_day_being_viewed(): void
    {
        $visit = Appointment::factory()->for($this->doctor)->for($this->treatment)->confirmed()->at('2026-11-05 10:00')->create();

        Livewire::test(Agenda::class)
            ->set('date', '2026-11-05')
            ->mountAction('blockSchedule')
            ->assertActionDataSet(['all_day' => true, 'from_day' => '2026-11-05', 'until_day' => '2026-11-05', 'doctor_id' => null, 'repeat_weeks' => 1])
            ->setActionData(['type' => 'other', 'reason' => 'Aniversario del consultorio'])
            ->callMountedAction();

        // El bloqueo no mueve la cita que ya estaba: se avisa.
        $this->assertSame(['Hay 1 cita en ese horario', 'Horario bloqueado'], $this->shownNotifications()->pluck('title')->all());

        $block = ScheduleBlock::sole();
        $this->assertSame(
            [null, '2026-11-05 00:00', '2026-11-06 00:00', 'Aniversario del consultorio', $this->reception->id],
            [$block->doctor_id, $block->starts_at->format('Y-m-d H:i'), $block->ends_at->format('Y-m-d H:i'), $block->reason, $block->created_by_user_id],
        );
        $this->assertSame(AppointmentStatus::Confirmed, $visit->fresh()->status);
    }

    public function test_a_block_of_only_some_hours_starts_from_the_morning_of_the_chosen_day(): void
    {
        Livewire::test(Agenda::class)
            ->set('date', '2026-11-05')
            ->mountAction('blockSchedule')
            ->assertMountedActionModalSee('Apágalo para bloquear solo unas horas.')
            ->setActionData(['all_day' => false])
            ->assertMountedActionModalSee('Enciéndelo para bloquear días completos.')
            ->assertActionDataSet(['starts_at' => '2026-11-05 09:00', 'ends_at' => '2026-11-05 13:00'])
            ->setActionData(['ends_at' => '2026-11-05 11:00'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $block = ScheduleBlock::sole();
        $this->assertSame(['2026-11-05 09:00', '2026-11-05 11:00'], [$block->starts_at->format('Y-m-d H:i'), $block->ends_at->format('Y-m-d H:i')]);
    }

    public function test_the_block_from_the_agenda_never_starts_in_the_past_and_follows_the_doctor_of_the_week(): void
    {
        $other = $this->doctorOffering($this->treatment);

        Livewire::test(Agenda::class)
            ->set('date', '2026-10-28')
            ->mountAction('blockSchedule')
            ->assertActionDataSet(['from_day' => '2026-11-02', 'until_day' => '2026-11-02', 'doctor_id' => null])
            ->unmountAction()
            ->set('mode', 'week')
            ->set('doctor', $other->id)
            ->mountAction('blockSchedule')
            ->assertActionDataSet(['doctor_id' => $other->id]);

        // El doctor bloquea su propia agenda.
        $this->actingAs($this->doctor->user);

        Livewire::test(Agenda::class)
            ->callAction('blockSchedule', ['from_day' => '2026-11-06', 'until_day' => '2026-11-06'])
            ->assertHasNoFormErrors();

        $this->assertSame($this->doctor->id, ScheduleBlock::sole()->doctor_id);
    }

    public function test_a_whole_day_block_goes_from_its_first_day_to_its_last(): void
    {
        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', ['doctor_id' => $this->doctor->id, 'type' => 'vacation', 'from_day' => '2026-11-09', 'until_day' => '2026-11-13'])
            ->assertHasNoFormErrors()
            ->assertSee('Todo el día');

        $block = ScheduleBlock::sole();
        $slots = fn (string $day) => app(AvailabilityService::class)->slotsFor($this->doctor, $this->treatment, CarbonImmutable::parse($day));

        $this->assertSame(['2026-11-09 00:00', '2026-11-14 00:00', ScheduleBlockType::Vacation], [$block->starts_at->format('Y-m-d H:i'), $block->ends_at->format('Y-m-d H:i'), $block->type]);
        $this->assertSame('del lun. 09/11 al vie. 13/11 (días completos)', $block->span());
        $this->assertEmpty($slots('2026-11-13')->all());
        $this->assertNotEmpty($slots('2026-11-14')->all());
    }

    public function test_whole_days_cannot_end_before_they_start_nor_be_already_over(): void
    {
        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', ['type' => 'vacation', 'from_day' => '2026-11-13', 'until_day' => '2026-11-09'])
            ->assertHasFormErrors(['until_day']);

        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', ['type' => 'vacation', 'from_day' => '2026-10-26', 'until_day' => '2026-10-30'])
            ->assertHasFormErrors(['until_day' => 'Ese horario ya pasó. Revisa la fecha, porque un bloqueo solo sirve de ahora en adelante.']);

        $this->assertSame(0, ScheduleBlock::count());
    }

    public function test_a_block_can_repeat_the_same_hours_every_week(): void
    {
        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', [
                'doctor_id' => $this->doctor->id,
                'type' => 'course',
                'all_day' => false,
                'starts_at' => '2026-11-05 15:00',
                'ends_at' => '2026-11-05 19:00',
                'repeat_weeks' => 3,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Horario bloqueado en 3 semanas');

        $this->assertSame(
            ['2026-11-05 15:00', '2026-11-12 15:00', '2026-11-19 15:00'],
            ScheduleBlock::query()->orderBy('starts_at')->get()->map(fn (ScheduleBlock $block) => $block->starts_at->format('Y-m-d H:i'))->all(),
        );
    }

    public function test_correcting_a_block_shows_it_as_it_was_saved(): void
    {
        $days = ScheduleBlock::query()->create(['doctor_id' => $this->doctor->id, 'type' => 'vacation', 'starts_at' => '2026-11-09 00:00', 'ends_at' => '2026-11-14 00:00']);
        $hours = ScheduleBlock::query()->create(['doctor_id' => $this->doctor->id, 'type' => 'course', 'starts_at' => '2026-11-05 15:00', 'ends_at' => '2026-11-05 19:00']);

        Livewire::test(ManageScheduleBlocks::class)
            ->mountAction(TestAction::make('edit')->table($hours))
            ->assertActionDataSet(['all_day' => false])
            ->unmountAction()
            ->mountAction(TestAction::make('edit')->table($days))
            ->assertActionDataSet(['all_day' => true, 'from_day' => '2026-11-09', 'until_day' => '2026-11-13'])
            ->setActionData(['until_day' => '2026-11-12'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(['2026-11-09 00:00', '2026-11-13 00:00'], [$days->fresh()->starts_at->format('Y-m-d H:i'), $days->fresh()->ends_at->format('Y-m-d H:i')]);
        $this->assertSame(['2026-11-05 15:00', '2026-11-05 19:00'], [$hours->fresh()->starts_at->format('Y-m-d H:i'), $hours->fresh()->ends_at->format('Y-m-d H:i')]);
    }

    public function test_the_holidays_of_the_year_are_loaded_at_once_as_closed_days(): void
    {
        Livewire::test(ManageScheduleBlocks::class)
            ->mountAction('loadHolidays')
            // Los que quedan del año ya vienen marcados.
            ->assertActionDataSet(['year' => 2026, 'days' => ['2026-12-08', '2026-12-09', '2026-12-25']])
            ->assertMountedActionModalSee(['Martes 8 de diciembre · Inmaculada Concepción', 'Viernes 25 de diciembre · Navidad'])
            ->setActionData(['days' => ['2026-12-08', '2026-12-25']])
            ->callMountedAction()
            ->assertNotified('2 feriados bloqueados');

        $this->assertSame(
            [[null, ScheduleBlockType::Holiday, '2026-12-08', 'Inmaculada Concepción'], [null, ScheduleBlockType::Holiday, '2026-12-25', 'Navidad']],
            ScheduleBlock::query()->orderBy('starts_at')->get()->map(fn (ScheduleBlock $block) => [$block->doctor_id, $block->type, $block->starts_at->toDateString(), $block->reason])->all(),
        );

        // Con otro año, quedan marcados todos los de ese.
        Livewire::test(ManageScheduleBlocks::class)
            ->mountAction('loadHolidays')
            ->assertActionDataSet(['days' => ['2026-12-09']])
            ->setActionData(['year' => 2027])
            ->assertActionDataSet(fn (array $data) => count($data['days']) === 16 && $data['days'][0] === '2027-01-01');
    }

    public function test_loading_holidays_needs_at_least_one_and_is_for_who_manages_every_agenda(): void
    {
        Livewire::test(ManageScheduleBlocks::class)
            ->mountAction('loadHolidays')
            ->setActionData(['days' => []])
            ->callMountedAction()
            ->assertHasFormErrors(['days' => 'Marca al menos un feriado.']);

        $this->actingAs($this->doctor->user);

        Livewire::test(ManageScheduleBlocks::class)->assertActionHidden('loadHolidays');
        $this->assertSame(0, ScheduleBlock::count());
    }
}
