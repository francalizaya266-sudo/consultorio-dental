<?php

namespace Tests\Feature\Services\Agenda;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\Doctors\RelationManagers\TreatmentsRelationManager;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class LearnedDurationsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['name' => 'Endodoncia', 'duration_minutes' => 30, 'buffer_minutes' => 10]);
        $this->doctor = $this->doctorOffering($this->treatment);
    }

    public function test_the_agenda_reserves_what_the_doctor_really_takes(): void
    {
        // Seis atenciones medidas: suele tardar 45 min. La de 5 horas (se olvidaron de cerrarla) no cuenta.
        $this->attended([40, 45, 45, 50, 45, 300]);

        $this->artisan('agenda:aprender-duraciones')->assertSuccessful();

        $this->assertSame(45, $this->doctor->fresh()->durationFor($this->treatment));
        $slot = app(AvailabilityService::class)->slotsFor($this->doctor, $this->treatment, CarbonImmutable::parse('2026-11-04'))->first();
        $this->assertSame(['09:00', '09:45'], [$slot->startsAt->format('H:i'), $slot->endsAt->format('H:i')]);

        // Puesta a mano, manda la propia.
        $this->doctor->treatments()->updateExistingPivot($this->treatment->id, ['duration_minutes' => 60]);
        $this->assertSame(60, $this->doctor->fresh()->durationFor($this->treatment));
    }

    public function test_few_visits_or_a_small_difference_keep_the_general_duration(): void
    {
        $this->attended([45, 45, 45, 45]);
        $this->artisan('agenda:aprender-duraciones')->assertSuccessful();
        $this->assertSame(30, $this->doctor->fresh()->durationFor($this->treatment), 'Con 4 atenciones todavía no se confía.');

        $this->attended([35, 35, 35, 35, 35, 35]);
        $this->artisan('agenda:aprender-duraciones')->assertSuccessful();
        $this->assertSame(35, $this->doctor->fresh()->treatments()->sole()->pivot->learned_minutes);
        $this->assertSame(30, $this->doctor->fresh()->durationFor($this->treatment), '5 minutos de diferencia no cambian la agenda.');
    }

    public function test_the_doctor_page_says_where_each_duration_comes_from(): void
    {
        $this->attended([40, 45, 45, 50, 45]);
        $this->artisan('agenda:aprender-duraciones')->assertSuccessful();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(TreatmentsRelationManager::class, ['ownerRecord' => $this->doctor, 'pageClass' => EditDoctor::class])
            ->assertSee(['45 min', 'Lo que suele tardar: 5 atenciones medidas (general: 30 min)']);
    }

    /** @param  list<int>  $minutes  Lo que duró cada atención, de la más antigua a la más reciente. */
    private function attended(array $minutes): void
    {
        foreach ($minutes as $i => $length) {
            $start = CarbonImmutable::parse('2026-10-01 09:00')->addDays(count($minutes) - $i);

            Appointment::factory()->for($this->doctor)->for($this->treatment)->at($start->toDateTimeString())->create([
                'status' => AppointmentStatus::Completed,
                'started_at' => $start->addMinutes(5),
                'finished_at' => $start->addMinutes(5 + $length),
            ]);
        }
    }
}
