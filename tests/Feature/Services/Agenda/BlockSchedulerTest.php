<?php

namespace Tests\Feature\Services\Agenda;

use App\Enums\ScheduleBlockType;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\BlockScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class BlockSchedulerTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->reception = User::factory()->reception()->create();
    }

    public function test_a_block_repeats_the_same_weekday_and_hours_one_block_per_week(): void
    {
        $doctor = $this->doctorOffering();

        $blocks = app(BlockScheduler::class)->create($this->reception, [
            'doctor_id' => $doctor->id,
            'type' => 'course',
            'starts_at' => '2026-11-05 15:00',
            'ends_at' => '2026-11-05 19:00',
            'reason' => 'Curso de implantes',
        ], weeks: 3);

        $this->assertSame(
            [['2026-11-05 15:00', '2026-11-05 19:00'], ['2026-11-12 15:00', '2026-11-12 19:00'], ['2026-11-19 15:00', '2026-11-19 19:00']],
            $blocks->map(fn (ScheduleBlock $block) => [$block->starts_at->format('Y-m-d H:i'), $block->ends_at->format('Y-m-d H:i')])->all(),
        );
        $this->assertSame(
            [[$doctor->id, ScheduleBlockType::Course, 'Curso de implantes', $this->reception->id]],
            ScheduleBlock::query()->get()->map(fn (ScheduleBlock $block) => [$block->doctor_id, $block->type, $block->reason, $block->created_by_user_id])->unique()->values()->all(),
        );
    }

    public function test_the_weeks_stay_between_one_and_the_most_allowed(): void
    {
        $block = ['type' => 'other', 'starts_at' => '2026-11-05 15:00', 'ends_at' => '2026-11-05 19:00'];

        $this->assertCount(1, app(BlockScheduler::class)->create($this->reception, $block, weeks: 0));
        $this->assertCount(BlockScheduler::REPEAT_MAX_WEEKS, app(BlockScheduler::class)->create($this->reception, $block, weeks: 80));
    }

    public function test_the_holidays_left_to_load_leave_out_the_past_and_the_days_the_whole_clinic_already_closed(): void
    {
        $doctor = $this->doctorOffering();
        // Navidad ya está cerrada para todos. El 8 solo falta un doctor y el 9 solo se cerró la mañana: siguen pendientes.
        ScheduleBlock::query()->create(['doctor_id' => null, 'type' => 'holiday', 'starts_at' => '2026-12-25 00:00', 'ends_at' => '2026-12-26 00:00']);
        ScheduleBlock::query()->create(['doctor_id' => $doctor->id, 'type' => 'vacation', 'starts_at' => '2026-12-08 00:00', 'ends_at' => '2026-12-09 00:00']);
        ScheduleBlock::query()->create(['doctor_id' => null, 'type' => 'other', 'starts_at' => '2026-12-09 08:00', 'ends_at' => '2026-12-09 13:00']);

        $this->assertSame(
            ['2026-12-08' => 'Inmaculada Concepción', '2026-12-09' => 'Batalla de Ayacucho'],
            app(BlockScheduler::class)->pendingHolidays(2026),
        );
        $this->assertCount(16, app(BlockScheduler::class)->pendingHolidays(2027));
    }

    public function test_loading_holidays_closes_those_whole_days_for_every_doctor(): void
    {
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);

        // Repetida, una fecha que no es feriado y un feriado que ya pasó: no se bloquean.
        $blocks = app(BlockScheduler::class)->loadHolidays($this->reception, ['2026-12-25', '2026-12-08', '2026-12-08', '2026-12-24', '2026-01-01']);

        $this->assertSame(
            [
                [null, ScheduleBlockType::Holiday, '2026-12-08 00:00', '2026-12-09 00:00', 'Inmaculada Concepción'],
                [null, ScheduleBlockType::Holiday, '2026-12-25 00:00', '2026-12-26 00:00', 'Navidad'],
            ],
            $blocks->map(fn (ScheduleBlock $block) => [$block->doctor_id, $block->type, $block->starts_at->format('Y-m-d H:i'), $block->ends_at->format('Y-m-d H:i'), $block->reason])->all(),
        );
        $this->assertTrue($blocks->first()->isWholeDays());
        $this->assertEmpty(app(AvailabilityService::class)->slotsFor($doctor, $treatment, CarbonImmutable::parse('2026-12-08'))->all());
        $this->assertNotEmpty(app(AvailabilityService::class)->slotsFor($doctor, $treatment, CarbonImmutable::parse('2026-12-09'))->all());

        // Cargarlos otra vez no los duplica.
        $this->assertCount(0, app(BlockScheduler::class)->loadHolidays($this->reception, ['2026-12-25', '2026-12-08']));
        $this->assertSame(2, ScheduleBlock::count());
    }
}
