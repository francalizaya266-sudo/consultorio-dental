<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Agenda;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Livewire\PanelPulse;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Dashboard\TodayPulse;
use App\Services\StaffAlerts;
use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Lo que guarda una persona lo ven las demás en pocos segundos, sin recargar la página. */
class LivePanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Doctor $doctor;

    private Appointment $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $this->doctor = $this->doctorOffering($treatment);
        $this->rosa = Appointment::factory()
            ->for($this->doctor)
            ->for($treatment)
            ->for(Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']))
            ->at('2026-11-02 10:00')
            ->create();
    }

    public function test_the_doctor_screen_learns_in_its_next_check_that_reception_marked_the_arrival(): void
    {
        $reception = User::factory()->reception()->create();
        $this->actingAs($this->doctor->user);

        // La pantalla del doctor está abierta y nadie guardó nada: no se vuelve a pintar.
        $screen = Livewire::test(PanelPulse::class)
            ->call('check')
            ->assertNotDispatched(Dashboard::CHANGED)
            ->assertNotNotified();

        app(AppointmentService::class)->markArrived($this->rosa, $reception);

        // En la siguiente consulta se entera: se pintan las tarjetas, la campana se pone al día y sale el aviso.
        $screen->call('check')
            ->assertDispatched(Dashboard::CHANGED)
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified('Llegó Rosa Quispe');

        // Y no lo repite mientras no pase nada más.
        $screen->call('check')
            ->assertNotDispatched(Dashboard::CHANGED)
            ->assertNotNotified();
    }

    public function test_the_screen_where_the_change_was_made_is_not_painted_twice(): void
    {
        $this->actingAs(User::factory()->reception()->create());
        $screen = Livewire::test(PanelPulse::class);

        app(AppointmentService::class)->markArrived($this->rosa, auth()->user());

        // La página ya avisó a sus tarjetas al terminar la acción: el pulso solo toma nota.
        $screen->call('acknowledge')->assertNotDispatched(Dashboard::CHANGED);
        $screen->call('check')->assertNotDispatched(Dashboard::CHANGED);
    }

    public function test_the_doctor_is_told_their_patient_is_waiting_until_they_call_them_in(): void
    {
        $reception = User::factory()->reception()->create();
        $appointments = app(AppointmentService::class);

        $appointments->markArrived($this->rosa, $reception);

        $alert = $this->doctor->user->unreadNotifications()->sole();
        $this->assertSame('Llegó Rosa Quispe', $alert->data['title']);
        $this->assertSame('Te espera en sala. Cita de las 10:00 · Limpieza dental.', $alert->data['body']);
        // Recepción lo marcó: a ella no le llega nada.
        $this->assertSame(0, $reception->notifications()->count());

        // Al pasarla a consulta, el aviso ya cumplió: queda como leído y la campana vuelve a cero.
        $appointments->markStarted($this->rosa, $this->doctor->user);
        $this->assertSame(0, $this->doctor->user->unreadNotifications()->count());
        $this->assertSame(1, $this->doctor->user->notifications()->count());
    }

    public function test_the_doctor_who_marks_the_arrival_is_not_told_what_they_just_did(): void
    {
        app(AppointmentService::class)->markArrived($this->rosa, $this->doctor->user);

        $this->assertSame(0, $this->doctor->user->notifications()->count());
    }

    public function test_a_doctor_whose_user_is_no_longer_active_gets_no_alert(): void
    {
        $this->doctor->user->update(['is_active' => false]);

        app(AppointmentService::class)->markArrived($this->rosa, User::factory()->reception()->create());

        $this->assertSame(0, $this->doctor->user->notifications()->count());
    }

    public function test_a_new_alert_in_the_bell_pops_up_once_and_older_ones_do_not(): void
    {
        $reception = User::factory()->reception()->create();
        $alerts = app(StaffAlerts::class);
        $alerts->toReception('Se liberó un horario', 'Lunes a las 10:00.');
        $this->actingAs($reception);

        // Lo que ya estaba en la campana al abrir la pantalla no vuelve a saltar.
        $this->travel(5)->seconds();
        $screen = Livewire::test(PanelPulse::class);
        $this->rosa->touch();
        $screen->call('check')->assertDispatched(Dashboard::CHANGED)->assertNotNotified();

        // Llegan cuatro de golpe: saltan tres y el resto queda en la campana.
        collect(['Uno', 'Dos', 'Tres', 'Cuatro'])->each(fn (string $title) => $alerts->toReception($title));
        $screen->call('check')->assertDispatched('databaseNotificationsSent');
        $this->assertCount(3, $this->poppedUp());
        $this->assertSame(5, $reception->unreadNotifications()->count());

        // Otro aviso en ese mismo segundo sí salta; los de antes, no.
        $alerts->toReception('Cinco');
        $screen->call('check');
        $this->assertSame(['Cinco'], $this->poppedUp());
    }

    public function test_the_mark_moves_only_when_something_of_the_day_is_really_saved(): void
    {
        $mark = TodayPulse::stamp();

        // Lo que no cambia la agenda ni la caja no molesta a las pantallas.
        Treatment::factory()->create();
        $this->assertSame($mark, TodayPulse::stamp());

        // Dentro de una transacción la marca espera: quien la viera antes leería los datos viejos.
        DB::transaction(function () use ($mark) {
            $this->rosa->update(['internal_notes' => 'Viene con su mamá']);
            $this->assertSame($mark, TodayPulse::stamp());
        });
        $this->assertNotSame($mark, $mark = TodayPulse::stamp());

        // Si el cambio no llegó a guardarse, no pasó nada.
        try {
            DB::transaction(function () {
                $this->rosa->update(['internal_notes' => 'No se guarda']);

                throw new RuntimeException('Algo falló antes de terminar');
            });
        } catch (RuntimeException) {
            // Era lo esperado.
        }
        $this->assertSame($mark, TodayPulse::stamp());

        // Borrar también cuenta.
        $this->rosa->delete();
        $this->assertNotSame($mark, TodayPulse::stamp());
    }

    public function test_the_pulse_is_on_every_screen_of_whoever_is_signed_in_and_the_agenda_and_the_list_listen(): void
    {
        $this->get(Filament::getPanel('admin')->getLoginUrl())->assertOk()->assertDontSeeLivewire(PanelPulse::class);

        $this->actingAs(User::factory()->reception()->create());
        $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(PanelPulse::class)->assertSee('wire:poll.3s="check"', escape: false);
        $this->get(Agenda::getUrl())->assertOk()->assertSeeLivewire(PanelPulse::class);

        // La grilla y la lista del día se vuelven a pintar con el mismo aviso que las tarjetas de "Hoy".
        Livewire::test(Agenda::class)->assertSeeHtml('x-on:today-changed.window="refresh()"');
        Livewire::withQueryParams(['mode' => 'list'])->test(Agenda::class)->assertSeeHtml('x-on:today-changed.window="refresh()"');
        Livewire::test(ListAppointments::class)->dispatch(Dashboard::CHANGED)->assertOk();
    }

    /**
     * Los títulos de los avisos que saltaron en pantalla desde la última vez que se miró.
     *
     * @return list<string>
     */
    private function poppedUp(): array
    {
        $mailbox = app(NotificationMailbox::class);
        $mailbox->flush();

        return array_column($mailbox->pull(), 'title');
    }
}
