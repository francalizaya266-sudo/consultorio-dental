<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\FollowUpsToday;
use App\Models\DeskNote;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class DeskNotesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_reception_writes_down_a_task_and_it_shows_the_day_it_is_due(): void
    {
        $this->freezeMonday();
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->actingAs($ana = User::factory()->reception()->create(['name' => 'Ana']));

        Livewire::test(FollowUpsToday::class)
            ->assertDontSee('Llamar al laboratorio')
            ->callAction('addNote', ['body' => 'Llamar al laboratorio por la corona', 'due_on' => '2026-11-02', 'patient_id' => $rosa->id])
            ->assertNotified('Pendiente anotado')
            ->callAction('addNote', ['body' => 'Viene el técnico del sillón', 'due_on' => '2026-11-06']);

        // Lo de otro día todavía no sale.
        Livewire::test(FollowUpsToday::class)
            ->assertSeeInOrder(['Llamar al laboratorio por la corona', 'Para hoy', 'Rosa Quispe', 'Hecho'])
            ->assertDontSee('Viene el técnico del sillón');

        $this->assertSame([$ana->id, $rosa->id], [DeskNote::first()->created_by_user_id, DeskNote::first()->patient_id]);

        $this->travelTo(CarbonImmutable::parse('2026-11-06 08:00', 'America/Lima'));

        // Lo que nadie hizo sigue ahí, marcado como atrasado, hasta que alguien lo haga.
        Livewire::test(FollowUpsToday::class)
            ->assertSeeInOrder(['Llamar al laboratorio por la corona', 'Desde el lun. 2 nov.', 'Viene el técnico del sillón', 'Para hoy']);
    }

    public function test_what_is_done_stays_crossed_out_for_the_next_shift_and_is_gone_the_next_day(): void
    {
        $this->freezeMonday();
        $note = DeskNote::factory()->create(['body' => 'Pedir más recibos a la imprenta']);
        $this->actingAs(User::factory()->reception()->create(['name' => 'Ana']));

        Livewire::test(FollowUpsToday::class)->callAction('noteDone', arguments: ['note' => $note->id]);

        Livewire::test(FollowUpsToday::class)
            ->assertSeeInOrder(['Pedir más recibos a la imprenta', 'Hecho por Ana a las 08:00', 'Deshacer'])
            ->callAction('noteUndo', arguments: ['note' => $note->id]);

        Livewire::test(FollowUpsToday::class)
            ->assertSeeInOrder(['Pedir más recibos a la imprenta', 'Para hoy'])
            ->callAction('noteDone', arguments: ['note' => $note->id]);

        $this->assertTrue($note->refresh()->isDone());

        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00', 'America/Lima'));

        Livewire::test(FollowUpsToday::class)->assertDontSee('Pedir más recibos');
    }

    public function test_doctors_cannot_write_or_close_reception_tasks(): void
    {
        $note = DeskNote::factory()->create();
        $doctor = User::factory()->doctor()->create();

        $this->assertFalse($doctor->can('create', DeskNote::class));
        $this->assertFalse($doctor->can('update', $note));
        $this->assertFalse(FollowUpsToday::canView());
    }
}
