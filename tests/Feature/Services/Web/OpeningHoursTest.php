<?php

namespace Tests\Feature\Services\Web;

use App\Models\Doctor;
use App\Services\Web\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OpeningHoursTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const MORNING_AND_AFTERNOON = [['09:00', '13:00'], ['15:00', '19:00']];

    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'America/Lima');
    }

    public function test_the_clinic_hours_come_from_the_active_doctors_schedules(): void
    {
        Doctor::factory()->withSchedule()->create();
        // Otra doctora que se queda hasta las 8 los martes y atiende los sábados en la mañana.
        Doctor::factory()->withSchedule([['18:00', '20:00']], [2])->withSchedule([['09:00', '12:00']], [6])->create();
        Doctor::factory()->withSchedule([['07:00', '22:00']], [7])->create(['is_active' => false]);

        $hours = OpeningHours::fromSchedules();

        $this->assertSame([['09:00', '13:00'], ['15:00', '20:00']], $hours->forDay(2));
        $this->assertSame([], $hours->forDay(7));
        $this->assertSame([
            ['days' => 'Lunes', 'hours' => ["9:00\u{A0}a.\u{A0}m. – 1:00\u{A0}p.\u{A0}m.", "3:00\u{A0}p.\u{A0}m. – 7:00\u{A0}p.\u{A0}m."]],
            ['days' => 'Martes', 'hours' => ["9:00\u{A0}a.\u{A0}m. – 1:00\u{A0}p.\u{A0}m.", "3:00\u{A0}p.\u{A0}m. – 8:00\u{A0}p.\u{A0}m."]],
            ['days' => 'Miércoles a sábado', 'hours' => ["9:00\u{A0}a.\u{A0}m. – 1:00\u{A0}p.\u{A0}m.", "3:00\u{A0}p.\u{A0}m. – 7:00\u{A0}p.\u{A0}m."]],
            ['days' => 'Domingo', 'hours' => ['Cerrado']],
        ], $hours->lines());
    }

    public function test_says_whether_it_is_open_now_and_when_it_opens(): void
    {
        $week = array_fill(1, 6, self::MORNING_AND_AFTERNOON) + [7 => []];
        $hours = new OpeningHours($week);

        // Lunes 2 de noviembre de 2026.
        $this->assertSame("Cerrado ahora · abre hoy a las 9:00\u{A0}a.\u{A0}m.", $hours->status($this->at('2026-11-02 08:00')));
        $this->assertSame("Abierto ahora · cierra a la 1:00\u{A0}p.\u{A0}m.", $hours->status($this->at('2026-11-02 10:30')));
        $this->assertTrue($hours->isOpen($this->at('2026-11-02 10:30')));
        $this->assertSame("Cerrado ahora · abre hoy a las 3:00\u{A0}p.\u{A0}m.", $hours->status($this->at('2026-11-02 13:00')));
        $this->assertFalse($hours->isOpen($this->at('2026-11-02 13:00')));
        $this->assertSame("Cerrado ahora · abre mañana a las 9:00\u{A0}a.\u{A0}m.", $hours->status($this->at('2026-11-02 19:30')));
        // Sábado en la noche: el domingo no se atiende.
        $this->assertSame("Cerrado ahora · abre el lunes a las 9:00\u{A0}a.\u{A0}m.", $hours->status($this->at('2026-11-07 20:00')));

        $this->assertNull((new OpeningHours([]))->status($this->at('2026-11-02 10:00')));
    }

    public function test_google_gets_one_block_per_time_range_with_its_days(): void
    {
        $hours = new OpeningHours([1 => self::MORNING_AND_AFTERNOON, 2 => self::MORNING_AND_AFTERNOON, 6 => [['09:00', '13:00']]]);

        $this->assertSame([
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Saturday'], 'opens' => '09:00', 'closes' => '13:00'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday'], 'opens' => '15:00', 'closes' => '19:00'],
        ], $hours->specification());
    }
}
