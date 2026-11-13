<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\ScheduleBlocks\Pages\ManageScheduleBlocks;
use App\Filament\Resources\ScheduleBlocks\ScheduleBlockResource;
use App\Models\Doctor;
use App\Models\ScheduleBlock;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** La ficha del doctor: el horario en una fila por día, el color con botones y el paso a sus bloqueos. */
class DoctorWeekScheduleTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
    }

    public function test_the_week_has_one_row_per_day_and_says_which_days_the_doctor_does_not_attend(): void
    {
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00'], ['15:00', '19:00']], [1, 3])->create();
        $this->actingAs(User::factory()->admin()->create());

        $page = Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->assertSeeInOrder(['Horario de atención', 'Lunes', 'Martes', 'No atiende', 'Miércoles', 'Jueves', 'No atiende', 'Viernes', 'Sábado', 'Domingo', 'No atiende']);

        // Cinco días sin tramos; y "copiar a otros días" se ofrece una vez por día con horario, no una por tramo.
        $this->assertSame(5, substr_count($page->html(), 'No atiende'));
        $this->assertSame(2, substr_count($page->html(), 'Copiar a otros días'));
        $this->assertSame(7, substr_count($page->html(), 'Agregar tramo'));
    }

    public function test_a_range_is_added_on_its_day_with_the_usual_hours_already_written(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $addOn = fn (int $weekday): TestAction => TestAction::make('addRange')->schemaComponent('schedules')->arguments(['weekday' => $weekday]);

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            // El primero del día, la mañana; el segundo, la tarde.
            ->callAction($addOn(2))
            ->callAction($addOn(2))
            ->callAction($addOn(6))
            // Un día que no existe no agrega nada.
            ->callAction($addOn(9))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['2 09:00–13:00', '2 15:00–19:00', '6 09:00–13:00'],
            $doctor->schedules()->orderBy('weekday')->orderBy('starts_at')->get()
                ->map(fn ($range) => $range->weekday.' '.substr((string) $range->starts_at, 0, 5).'–'.substr((string) $range->ends_at, 0, 5))->all(),
        );
    }

    public function test_a_third_range_of_the_day_comes_empty_and_must_be_filled_in(): void
    {
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00'], ['15:00', '19:00']], [1])->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->callAction(TestAction::make('addRange')->schemaComponent('schedules')->arguments(['weekday' => 1]))
            ->call('save')
            ->assertHasFormErrors();

        $this->assertSame(2, $doctor->schedules()->count());
    }

    public function test_ranges_that_overlap_are_caught_as_the_hour_picker_writes_them(): void
    {
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00']], [1])->create();
        $this->actingAs(User::factory()->admin()->create());

        // En pantalla, el selector de hora guarda cada hora con la fecha de hoy delante.
        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->callAction(TestAction::make('addRange')->schemaComponent('schedules')->arguments(['weekday' => 1]))
            ->tap(function (Testable $page): void {
                $ranges = $page->get('data.schedules');
                $added = array_key_last($ranges);
                $this->assertSame(['2026-11-02 15:00:00', '2026-11-02 19:00:00'], [$ranges[$added]['starts_at'], $ranges[$added]['ends_at']]);

                $page->set("data.schedules.{$added}.starts_at", '2026-11-02 12:00:00');
            })
            ->call('save')
            ->assertHasFormErrors(['schedules'])
            ->assertSee('El lunes tiene dos tramos que se cruzan: 09:00–13:00 y 12:00–19:00. Deja uno solo o cámbiales la hora.');

        $this->assertSame(1, $doctor->schedules()->count());
    }

    public function test_the_color_in_the_agenda_is_one_of_the_named_buttons(): void
    {
        $doctor = Doctor::factory()->create(['agenda_color' => '#0EA5E9']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->assertSeeInOrder(['Color en la agenda', 'Celeste', 'Verde', 'Ámbar', 'Violeta', 'Rosa', 'Turquesa', 'Naranja', 'Gris'])
            ->assertDontSee('El que tenía')
            // El que tenía guardado queda marcado, aunque estuviera escrito en mayúsculas.
            ->assertSchemaStateSet(['agenda_color' => '#0ea5e9'])
            ->fillForm(['agenda_color' => '#10b981'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('#10b981', $doctor->refresh()->agenda_color);

        // Un código suelto ya no se puede escribir.
        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->fillForm(['agenda_color' => '#ffffff'])
            ->call('save')
            ->assertHasFormErrors(['agenda_color']);

        $this->assertSame('#10b981', $doctor->refresh()->agenda_color);
    }

    public function test_a_color_chosen_before_the_buttons_existed_is_kept_as_one_more_button(): void
    {
        $doctor = Doctor::factory()->create(['agenda_color' => '#123456']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->assertSee('El que tenía')
            ->fillForm(['specialty' => 'Ortodoncia'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['#123456', 'Ortodoncia'], [$doctor->refresh()->agenda_color, $doctor->specialty]);
    }

    public function test_a_new_doctor_is_offered_a_color_nobody_uses_yet(): void
    {
        Doctor::factory()->create(['agenda_color' => '#0ea5e9']);
        Doctor::factory()->create(['agenda_color' => '#10B981'])->delete();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateDoctor::class)->assertSchemaStateSet(['agenda_color' => '#f59e0b']);
    }

    public function test_the_doctor_page_saves_with_guardar_and_keeps_the_button_in_sight(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(CreateDoctor::$formActionsAreSticky);
        $this->assertTrue(EditDoctor::$formActionsAreSticky);

        $page = Livewire::test(CreateDoctor::class)
            ->assertSee('Pásalo a la página web cuando su ficha esté completa: foto, horario y tratamientos.');

        // El botón que guarda dice "Guardar", como en las ventanas de alta: decía "Crear".
        preg_match_all('/<button[^>]*type="submit"[^>]*>(.*?)<\/button>/s', $page->html(), $submitButtons);
        $this->assertSame(['Guardar'], array_map(fn (string $button) => trim(strip_tags($button)), $submitButtons[1]));
    }

    public function test_the_schedule_leads_to_the_days_off_of_that_doctor(): void
    {
        $ana = Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        $luis = Doctor::factory()->create(['name' => 'Dr. Luis Ramos']);
        $block = fn (?Doctor $doctor, string $reason, string $day = '2026-11-10'): ScheduleBlock => ScheduleBlock::create([
            'doctor_id' => $doctor?->getKey(),
            'type' => 'vacation',
            'starts_at' => $day.' 00:00',
            'ends_at' => $day.' 23:59',
            'reason' => $reason,
        ]);
        $hers = $block($ana, 'Congreso de Ana');
        $his = $block($luis, 'Viaje de Luis');
        $everyones = $block(null, 'Feriado del consultorio');
        $block($ana, 'Curso que ya pasó', '2026-10-01');
        $this->actingAs(User::factory()->admin()->create());

        $herDaysOff = ScheduleBlockResource::urlForDoctor($ana);

        Livewire::test(EditDoctor::class, ['record' => $ana->getKey()])
            ->assertSee('Vacaciones, permisos y feriados')
            ->assertSee($herDaysOff);

        // El enlace abre los bloqueos ya filtrados por ese doctor, y solo los vigentes, como abre siempre la lista.
        $this->get($herDaysOff)
            ->assertOk()
            ->assertSee(['Congreso de Ana', 'Feriado del consultorio'])
            ->assertDontSee(['Viaje de Luis', 'Curso que ya pasó']);

        // Con el doctor elegido: lo suyo y lo de todo el consultorio. Sin elegir, todo.
        Livewire::test(ManageScheduleBlocks::class)
            ->assertCanSeeTableRecords([$hers, $his, $everyones])
            ->filterTable('doctor', $ana->getKey())
            ->assertCanSeeTableRecords([$hers, $everyones])
            ->assertCanNotSeeTableRecords([$his]);
    }

    public function test_a_doctor_who_only_sees_their_own_agenda_is_not_offered_the_filter_by_doctor(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAs($doctor->user);

        Livewire::test(ManageScheduleBlocks::class)->assertTableFilterHidden('doctor');

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageScheduleBlocks::class)->assertTableFilterVisible('doctor');
    }
}
