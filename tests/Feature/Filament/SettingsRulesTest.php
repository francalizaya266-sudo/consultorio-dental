<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsRulesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * @param  array<string, int>  $booking
     */
    #[DataProvider('rulesThatContradictEachOther')]
    public function test_booking_rules_that_contradict_each_other_are_not_saved(array $booking, string $field, string $message): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['booking' => $booking])
            ->call('save')
            ->assertHasFormErrors([$field])
            ->assertSee($message);

        $this->assertSame(45, Setting::get('booking.horizon_days'));
    }

    /** @return array<string, array{array<string, int>, string, string}> */
    public static function rulesThatContradictEachOther(): array
    {
        return [
            'se reserva con más anticipación de la que la web deja ver' => [
                ['min_lead_minutes' => 1440, 'horizon_days' => 1],
                'booking.min_lead_minutes',
                'Así la web no ofrece ningún horario',
            ],
            'el celular de la familia admite menos citas que un solo paciente' => [
                ['max_web_appointments_per_patient' => 5, 'max_web_appointments_per_phone' => 4],
                'booking.max_web_appointments_per_phone',
                'Debe ser igual o mayor que las citas por paciente',
            ],
            'para cambiar la cita hay que avisar antes de poder reservarla' => [
                ['self_reschedule_min_hours' => 48, 'horizon_days' => 2],
                'booking.self_reschedule_min_hours',
                'Así ningún paciente puede cambiar su cita solo',
            ],
            'para cancelar hay que avisar antes de poder reservar' => [
                ['self_cancel_min_hours' => 48, 'self_reschedule_min_hours' => 0, 'horizon_days' => 2],
                'booking.self_cancel_min_hours',
                'Así ningún paciente puede cancelar solo',
            ],
        ];
    }

    public function test_booking_rules_at_their_limit_are_saved(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['booking' => [
                'horizon_days' => 2,
                'min_lead_minutes' => 2879,
                'self_reschedule_min_hours' => 47,
                'self_cancel_min_hours' => 47,
                'max_web_appointments_per_patient' => 4,
                'max_web_appointments_per_phone' => 4,
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([2, 2879, 4], [Setting::get('booking.horizon_days'), Setting::get('booking.min_lead_minutes'), Setting::get('booking.max_web_appointments_per_phone')]);
    }

    public function test_slots_are_offered_only_at_steps_that_fall_on_round_hours(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['agenda' => ['slot_step_minutes' => 7]])
            ->call('save')
            ->assertHasFormErrors(['agenda.slot_step_minutes']);

        Livewire::test(Settings::class)
            ->fillForm(['agenda' => ['slot_step_minutes' => 20]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(20, (int) Setting::get('agenda.slot_step_minutes'));
    }

    public function test_the_landline_must_be_a_phone_number(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['clinic' => ['phone' => 'preguntar por Ana']])
            ->call('save')
            ->assertHasFormErrors(['clinic.phone'])
            ->assertSee('Escribe solo el número, por ejemplo 051 321456.');

        Livewire::test(Settings::class)
            ->fillForm(['clinic' => ['phone' => '(051) 321-456']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('(051) 321-456', Setting::get('clinic.phone'));
    }

    public function test_leaving_with_changes_not_saved_asks_first(): void
    {
        Livewire::test(Settings::class)
            ->assertSeeHtml('setUpFilamentUnsavedDataChangesAlert');
    }
}
