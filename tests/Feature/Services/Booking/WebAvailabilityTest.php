<?php

namespace Tests\Feature\Services\Booking;

use App\Models\Doctor;
use App\Models\Setting;
use App\Models\Treatment;
use App\Services\Booking\WebAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class WebAvailabilityTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private function availability(): WebAvailability
    {
        return app(WebAvailability::class);
    }

    public function test_offers_only_what_a_doctor_takes_online_with_the_evaluation_first(): void
    {
        $cleaning = Treatment::factory()->create(['sort_order' => 1]);
        $evaluation = Treatment::factory()->evaluation()->create(['sort_order' => 5]);
        $endodontics = Treatment::factory()->clinicOnly()->create();
        $orthodontics = Treatment::factory()->create();
        $this->doctorOffering($cleaning, $evaluation, $endodontics);
        Doctor::factory()->create(['accepts_online_booking' => false])->treatments()->attach($orthodontics);

        $this->assertSame([$evaluation->id, $cleaning->id], $this->availability()->treatments()->pluck('id')->all());
    }

    public function test_lists_days_with_free_times_within_the_clinic_horizon(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);

        $days = fn () => array_map(fn (CarbonImmutable $day) => $day->format('D d'), $this->availability()->days($treatment, collect([$doctor]), count: 7));

        // El domingo no atiende.
        $this->assertSame(['Mon 02', 'Tue 03', 'Wed 04', 'Thu 05', 'Fri 06', 'Sat 07', 'Mon 09'], $days());

        Setting::set('booking.horizon_days', 3);
        $this->assertSame(['Mon 02', 'Tue 03', 'Wed 04', 'Thu 05'], $days());
    }

    public function test_shows_first_the_times_that_leave_no_useless_gaps(): void
    {
        $slot = fn (string $time, int $waste) => ['time' => $time, 'doctor_id' => 1, 'recommended' => false, 'waste' => $waste];
        $tidy = collect([$slot('09:00', 0), $slot('09:15', 15), $slot('10:00', 0), $slot('11:00', 0)]);
        $few = collect([$slot('09:00', 0), $slot('09:15', 15)]);

        $this->assertSame(['09:00', '10:00', '11:00'], $this->availability()->suggested($tidy)->pluck('time')->all());
        $this->assertSame(['09:00', '09:15'], $this->availability()->suggested($few)->pluck('time')->all());
    }
}
