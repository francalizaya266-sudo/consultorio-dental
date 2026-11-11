<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\DayPeriod;
use App\Filament\Pages\Agenda;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Agenda\GapFinder;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** El panel para buscar horas libres (mini calendario, "Buscar hueco", lista de espera) y el día en lista. */
class AgendaFindAndListTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
    }

    public function test_the_month_shows_how_full_each_day_is_and_a_day_off_is_closed_not_full(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 50]);
        $doctor = $this->doctorOffering($treatment);

        // El martes, las ocho horas de atención con citas de 50 min más 10 de esterilización.
        foreach (['09:00', '10:00', '11:00', '12:00', '15:00', '16:00', '17:00', '18:00'] as $time) {
            Appointment::factory()->for($doctor)->for($treatment)->at("2026-11-03 {$time}", 50, 10)->create();
        }
        // El miércoles, dos horas de cuatro en la mañana; el jueves, de vacaciones.
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-04 09:00', 110, 10)->create();
        ScheduleBlock::query()->create(['doctor_id' => $doctor->id, 'type' => 'vacation', 'starts_at' => '2026-11-05 00:00', 'ends_at' => '2026-11-06 00:00']);

        $load = app(GapFinder::class)->occupancy(collect([$doctor]), CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-08'));

        $this->assertSame(['2026-11-03' => 100, '2026-11-04' => 25, '2026-11-05' => null, '2026-11-06' => 0, '2026-11-07' => 0, '2026-11-08' => null], $load);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSee(['Noviembre 2026', 'Martes 3 de noviembre · lleno', 'Miércoles 4 de noviembre · 25 % ocupado', 'Jueves 5 de noviembre · no se atiende'])
            ->call('shiftMonth', 1)
            ->assertSee('Diciembre 2026')
            ->call('shift', 1)
            ->assertSet('date', '2026-11-03')
            ->assertSee('Noviembre 2026');
    }

    public function test_find_a_gap_offers_the_next_free_times_and_opens_the_appointment_ready(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 10]);
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00', 30, 10)->create();
        $patient = Patient::factory()->create();

        $days = app(GapFinder::class)->nextSlots($treatment, $doctor->newCollection([$doctor->load('treatments')]), CarbonImmutable::parse('2026-11-02'));

        // Primero las que quedan pegadas a la cita de las 10:00 (no dejan huecos), una de la tarde, y ninguna a menos de una hora de otra.
        $this->assertSame(['2026-11-02', '2026-11-03', '2026-11-04'], array_map(fn (array $day) => $day['date']->toDateString(), $days));
        $this->assertSame(['09:20', '10:40', '11:45', '15:00'], array_column($days[0]['slots'], 'time'));
        $this->assertSame([true, true, false, false], array_column($days[0]['slots'], 'recommended'));
        $this->assertSame(['09:00', '10:00', '11:00', '15:00'], array_column($days[1]['slots'], 'time'));

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSee('Elige un tratamiento para ver cuándo entra.')
            ->call('findFor', $treatment->id)
            ->assertSet('findTreatment', $treatment->id)
            ->assertSeeHtmlInOrder(['Buscar hueco', 'Hoy', '09:20', '10:40', '11:45', '15:00', 'Mañana', '09:00', 'Miércoles 4'])
            ->mountAction('createAppointment', ['doctor' => $doctor->id, 'date' => '2026-11-03', 'time' => '15:00', 'treatment' => $treatment->id])
            ->assertActionDataSet(['treatment_id' => $treatment->id, 'doctor_id' => $doctor->id, 'date' => '2026-11-03', 'slot' => '15:00'])
            ->setActionData(['patient_id' => $patient->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('2026-11-03 15:00', $patient->appointments()->sole()->starts_at->format('Y-m-d H:i'));
    }

    public function test_a_second_tap_on_the_same_treatment_clears_the_search(): void
    {
        $treatment = Treatment::factory()->create();
        $this->doctorOffering($treatment);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->call('findFor', $treatment->id)
            ->call('findFor', $treatment->id)
            ->assertSet('findTreatment', null)
            ->call('findFor', (string) $treatment->id, false)
            ->call('findFor', (string) $treatment->id, false)
            ->assertSet('findTreatment', $treatment->id)
            ->call('findFor', '', false)
            ->assertSet('findTreatment', null);
    }

    public function test_the_waitlist_shows_who_fits_that_day_with_the_time_to_offer(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $carmen = WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Carmen', 'last_name' => 'Rojas']))->for($treatment)->create(['preferred_period' => DayPeriod::Afternoon]);
        WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Jorge']))->for($treatment)->removed()->create();
        WaitlistEntry::factory()->for(Patient::factory()->state(['first_name' => 'Inés']))->for($treatment)->create(['earliest_date' => '2026-11-10']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->assertSee(['De la lista de espera, caben hoy', 'Carmen Rojas', 'en la tarde', 'Ofrecer 15:00'])
            ->assertDontSee(['Jorge', 'Inés'])
            ->mountAction('offer', ['entry' => $carmen->id, 'doctor' => $doctor->id, 'date' => '2026-11-02', 'time' => '15:00'])
            ->assertActionDataSet(['doctor_id' => $doctor->id, 'date' => '2026-11-02', 'slot' => '15:00']);

        // El doctor no maneja la lista de espera: no la ve en su agenda.
        $this->actingAs($doctor->user);

        Livewire::test(Agenda::class)->assertDontSee('De la lista de espera');
    }

    public function test_the_day_list_puts_every_doctor_in_order_with_the_next_step(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $torres = $this->doctorOffering($treatment);
        $ramos = $this->doctorOffering($treatment);
        $lucia = Appointment::factory()->for($torres)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Lucía']))->confirmed()->at('2026-11-02 09:00')->create();
        Appointment::factory()->for($ramos)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Bruno']))->at('2026-11-02 10:00')->create(['unconfirmed_flagged_at' => now()]);
        Appointment::factory()->for($torres)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Carla']))->at('2026-11-02 11:00')->create(['status' => AppointmentStatus::CheckedIn]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->set('mode', 'list')
            ->assertSee(['3 citas · 1 en sala', 'Libre 09:40 – 11:00', $torres->name, $ramos->name])
            ->assertSeeHtmlInOrder(['Ahora · 08:00', 'Lucía', 'Llegó', 'Bruno', 'Llamar', 'Confirmar', 'Carla', 'Pasó a consulta'])
            ->callAction(TestAction::make('listArrived')->arguments(['id' => $lucia->id]))
            ->assertNotified('Paciente en sala');

        $this->assertSame(AppointmentStatus::CheckedIn, $lucia->fresh()->status);
    }

    public function test_a_past_day_in_the_list_offers_to_charge_and_has_no_free_times(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory()->state(['first_name' => 'Rosa']))->at('2026-10-30 10:00')->create(['status' => AppointmentStatus::Completed]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->set('mode', 'list')
            ->set('date', '2026-10-30')
            ->assertSee(['1 cita', 'Rosa', 'Atendida', 'Cobrar'])
            ->assertDontSee(['Libre ', 'Ahora ·'])
            ->set('date', '2026-11-08')
            ->assertSee('Nadie atiende este día.');
    }
}
