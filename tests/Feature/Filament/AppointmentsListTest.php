<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Widgets\FollowUpsToday;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** La lista de Citas: sus pestañas, sus números y su orden. */
class AppointmentsListTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->treatment);
        $this->actingAs(User::factory()->reception()->create());
    }

    public function test_unclosed_means_the_same_in_the_agenda_the_list_and_the_home_page(): void
    {
        $unmarked = $this->visit('Lucía', '2026-11-02 09:00');
        $inTheRoom = $this->visit('Bruno', '2026-11-02 09:30', ['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => '2026-11-02 09:25']);
        $leftInTheRoom = $this->visit('Carla', '2026-10-30 10:00', ['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => '2026-10-30 09:55']);
        $happeningNow = $this->visit('Diego', '2026-11-02 10:45');
        $later = $this->visit('Elena', '2026-11-02 16:00');
        $this->travelTo('2026-11-02 11:00');

        // La de hoy que nadie marcó y la que llegó otro día y nunca se cerró. Quien está en sala hoy sigue su curso.
        $list = Livewire::test(ListAppointments::class, ['activeTab' => 'unclosed'])
            ->assertCanSeeTableRecords([$unmarked, $leftInTheRoom])
            ->assertCanNotSeeTableRecords([$inTheRoom, $happeningNow, $later]);
        $this->assertSame('2', (string) $list->instance()->getTabs()['unclosed']->getBadge());

        Livewire::test(FollowUpsToday::class)
            ->assertSeeHtmlInOrder(['Citas sin cerrar', '<span class="dash-count is-warning">2</span>']);

        $agenda = Livewire::test(Agenda::class)->set('mode', 'list');
        $this->assertSame(1, substr_count($agenda->html(), 'class="tag tag-unclosed"'));
        $agenda->assertSeeHtmlInOrder(['Lucía', 'Sin cerrar', 'Bruno', 'En sala', 'Diego']);

        // Cada cita por venir o sin marcar está en una sola pestaña.
        Livewire::test(ListAppointments::class, ['activeTab' => 'upcoming'])
            ->assertCanSeeTableRecords([$happeningNow, $later])
            ->assertCanNotSeeTableRecords([$unmarked, $inTheRoom, $leftInTheRoom]);
    }

    public function test_the_number_on_to_confirm_is_the_number_of_rows_and_turns_to_warning_when_someone_must_be_called(): void
    {
        $tomorrow = $this->visit('Lucía', '2026-11-03 10:00', ['status' => AppointmentStatus::Pending, 'confirmed_at' => null]);
        $nextWeek = $this->visit('Bruno', '2026-11-09 10:00', ['status' => AppointmentStatus::Pending, 'confirmed_at' => null]);
        $confirmed = $this->visit('Carla', '2026-11-03 11:00');

        $list = Livewire::test(ListAppointments::class, ['activeTab' => 'pending'])
            ->assertCanSeeTableRecords([$tomorrow, $nextWeek])
            ->assertCanNotSeeTableRecords([$confirmed]);
        $tab = $list->instance()->getTabs()['pending'];
        $this->assertSame(['2', 'gray', 'Citas que todavía no confirman'], [(string) $tab->getBadge(), $tab->getBadgeColor(), $tab->getBadgeTooltip()]);

        $tomorrow->update(['unconfirmed_flagged_at' => now()]);

        $list = Livewire::test(ListAppointments::class, ['activeTab' => 'pending']);
        $tab = $list->instance()->getTabs()['pending'];
        $this->assertSame(['2', 'warning', '1 dice "Llamar": está a un día de su cita'], [(string) $tab->getBadge(), $tab->getBadgeColor(), $tab->getBadgeTooltip()]);

        // El aviso "citas sin confirmar" llega con este filtro: quedan las que toca llamar.
        $list->filterTable('to_call')
            ->assertCanSeeTableRecords([$tomorrow])
            ->assertCanNotSeeTableRecords([$nextWeek]);
    }

    public function test_today_and_tomorrow_are_no_longer_tabs_and_an_old_link_opens_the_first_one(): void
    {
        $list = Livewire::test(ListAppointments::class, ['activeTab' => 'today'])
            ->assertSet('activeTab', 'upcoming');

        $this->assertSame(['upcoming', 'pending', 'unclosed', 'all'], array_keys($list->instance()->getTabs()));
    }

    public function test_all_starts_with_the_latest_and_says_the_year_when_it_is_not_this_one(): void
    {
        $lastYear = $this->visit('Lucía', '2025-12-01 10:00', ['status' => AppointmentStatus::Completed]);
        $lastWeek = $this->visit('Bruno', '2026-10-30 10:00', ['status' => AppointmentStatus::Completed]);
        $thisWeek = $this->visit('Carla', '2026-11-05 10:00');
        $nextWeek = $this->visit('Diego', '2026-11-10 10:00');

        Livewire::test(ListAppointments::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$nextWeek, $thisWeek, $lastWeek, $lastYear], inOrder: true)
            ->assertTableColumnFormattedStateSet('starts_at', 'lun. 01/12/2025 10:00', $lastYear)
            ->assertTableColumnFormattedStateSet('starts_at', 'vie. 30/10 10:00', $lastWeek);

        // Lo que viene sigue en orden de llegada.
        Livewire::test(ListAppointments::class, ['activeTab' => 'upcoming'])
            ->assertCanSeeTableRecords([$thisWeek, $nextWeek], inOrder: true);
    }

    public function test_the_list_filters_by_a_range_of_dates_and_by_treatment(): void
    {
        $other = Treatment::factory()->create();
        $this->doctor->treatments()->attach($other);
        $monday = $this->visit('Lucía', '2026-11-02 10:00');
        $thursday = $this->visit('Bruno', '2026-11-05 10:00');
        $nextWeek = Appointment::factory()->for($this->doctor)->for($other)->confirmed()->at('2026-11-10 10:00')->create();

        Livewire::test(ListAppointments::class, ['activeTab' => 'all'])
            ->filterTable('dates', ['from' => '2026-11-03', 'until' => '2026-11-10'])
            ->assertCanSeeTableRecords([$thursday, $nextWeek])
            ->assertCanNotSeeTableRecords([$monday])
            ->assertSee('Del 03/11/2026 al 10/11/2026')
            ->filterTable('treatment_id', $other->id)
            ->assertCanSeeTableRecords([$nextWeek])
            ->assertCanNotSeeTableRecords([$thursday]);
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
}
