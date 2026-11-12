<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Filament\Pages\Dashboard;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\MedicalBackground;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\Concerns\ReadsNotifications;
use Tests\TestCase;

class WalkInTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase, ReadsNotifications;

    private Treatment $cleaning;

    private Doctor $busy;

    private Doctor $free;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $this->busy = $this->doctorOffering($this->cleaning);
        $this->free = $this->doctorOffering($this->cleaning);
        // Con nombre fijo: el de la fábrica sale al azar y, cuando tocaba "Pedro", se confundía con el paciente nuevo de estas pruebas.
        $booked = Patient::factory()->create(['first_name' => 'Carmen', 'last_name' => 'Rojas Díaz']);
        Appointment::factory()->for($this->busy)->for($this->cleaning)->for($booked)->at('2026-11-02 10:00')->confirmed()->create();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:07', 'America/Lima'));
        $this->actingAs(User::factory()->reception()->create());
    }

    public function test_who_comes_without_an_appointment_gets_todays_first_room_and_waits_in_the_room(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe', 'origin' => AppointmentOrigin::Recommendation]);
        MedicalBackground::create(['patient_id' => $rosa->id]);

        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->setActionData(['patient_id' => $rosa->id])
            ->setActionData(['treatment_id' => $this->cleaning->id])
            // Queda puesto quien se desocupa primero, y se dice a qué hora.
            ->assertActionDataSet(['doctor_id' => $this->free->id])
            ->assertMountedActionModalSee('Lo puede atender hoy a las 10:15.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $appointment = Appointment::query()->whereBelongsTo($rosa)->sole();

        $this->assertSame([$this->free->id, '10:15', AppointmentStatus::CheckedIn], [$appointment->doctor_id, $appointment->starts_at->format('H:i'), $appointment->status]);
        $this->assertSame('10:07', $appointment->checked_in_at->format('H:i'));
        // Lo tenemos delante: no se le prepara el aviso de "cita agendada".
        $this->assertSame(0, WhatsappMessage::count());

        $notice = $this->lastNotification();
        $this->assertSame(['Paciente en sala', 'Rosa Quispe pasa con '.$this->free->name.' a las 10:15.'], [$notice['title'], $notice['body']]);
    }

    public function test_a_new_patient_is_registered_in_the_same_window_and_told_what_is_missing(): void
    {
        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->setActionData(['new_patient' => true])
            ->setActionData(['patient' => ['first_name' => 'Pedro', 'last_name' => 'Huamán', 'phone' => '987654321', 'relationship' => 'self', 'document_type' => 'dni']])
            ->setActionData(['origin' => AppointmentOrigin::WalkIn->value, 'doctor_id' => $this->busy->id, 'treatment_id' => $this->cleaning->id])
            // Con quien está ocupado, el primer hueco es al terminar su cita.
            ->assertMountedActionModalSee('Lo puede atender hoy a las 10:')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $pedro = Patient::query()->where('first_name', 'Pedro')->sole();
        $appointment = Appointment::query()->whereBelongsTo($pedro)->sole();

        $this->assertSame([$this->busy->id, AppointmentStatus::CheckedIn, true], [$appointment->doctor_id, $appointment->status, $appointment->is_new_patient]);
        $this->assertTrue($appointment->starts_at->gte('2026-11-02 10:30'));
        // Lo que se preguntó al recibirlo queda en su ficha.
        $this->assertSame(AppointmentOrigin::WalkIn, $pedro->origin);

        $notice = $this->lastNotification();
        $this->assertStringStartsWith('Pedro Huamán pasa con '.$this->busy->name.' a las '.$appointment->starts_at->format('H:i').'.<br>Le falta: DNI y fecha de nacimiento.', $notice['body']);
        $this->assertSame(['Completar su ficha'], array_column($notice['actions'], 'label'));
    }

    public function test_when_the_doctor_has_no_room_left_today_nothing_is_created(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 18:50', 'America/Lima'));

        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->setActionData(['new_patient' => true])
            ->setActionData(['patient' => ['first_name' => 'Pedro', 'last_name' => 'Huamán', 'relationship' => 'self']])
            ->setActionData(['doctor_id' => $this->busy->id, 'treatment_id' => $this->cleaning->id])
            ->assertMountedActionModalSee('Ya no tiene un hueco hoy para ese tratamiento.')
            ->callMountedAction()
            ->assertNotified($this->busy->name.' ya no tiene un hueco hoy para ese tratamiento. Elige otro doctor, o agéndalo con "Nueva cita" para otro día.');

        $this->assertSame(1, Appointment::count());
        $this->assertFalse(Patient::query()->where('first_name', 'Pedro')->exists());
    }

    public function test_who_already_had_an_appointment_today_is_marked_as_arrived_instead_of_getting_another(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe', 'origin' => AppointmentOrigin::Recommendation]);
        MedicalBackground::create(['patient_id' => $rosa->id]);
        $booked = Appointment::factory()->for($this->busy)->for($this->cleaning)->for($rosa)->at('2026-11-02 12:00')->confirmed()->create();

        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->setActionData(['patient_id' => $rosa->id])
            ->assertMountedActionModalSee(['Ya tiene cita hoy', '12:00 · Limpieza dental con '.$this->busy->name, 'Marcar que llegó a esa cita'])
            // Ya no se elige tratamiento ni doctor: se usa la cita que tenía.
            ->assertMountedActionModalDontSee('Tratamiento')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertTrue(Appointment::query()->whereBelongsTo($rosa)->sole()->is($booked));
        $this->assertSame([AppointmentStatus::CheckedIn, '10:07'], [$booked->refresh()->status, $booked->checked_in_at->format('H:i')]);

        $notice = $this->lastNotification();
        $this->assertSame(['Paciente en sala', 'Rosa Quispe llegó a su cita de las 12:00 con '.$this->busy->name.'.'], [$notice['title'], $notice['body']]);
    }

    public function test_it_is_still_a_separate_visit_when_reception_says_it_comes_for_something_else(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $booked = Appointment::factory()->for($this->busy)->for($this->cleaning)->for($rosa)->at('2026-11-02 12:00')->confirmed()->create();

        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->setActionData(['patient_id' => $rosa->id])
            ->setActionData(['use_appointment' => false])
            ->setActionData(['treatment_id' => $this->cleaning->id])
            ->assertMountedActionModalSee('Lo puede atender hoy a las 10:15.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(AppointmentStatus::Confirmed, $booked->refresh()->status);
        $this->assertSame(['10:15', AppointmentStatus::CheckedIn], [($walkIn = Appointment::query()->whereBelongsTo($rosa)->whereKeyNot($booked->id)->sole())->starts_at->format('H:i'), $walkIn->status]);
    }

    public function test_who_is_already_in_the_room_is_pointed_out_before_opening_another_visit(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        Appointment::factory()->for($this->busy)->for($this->cleaning)->for($rosa)->at('2026-11-02 12:00')->create(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => now()]);

        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->setActionData(['patient_id' => $rosa->id])
            ->assertMountedActionModalSee(['Ya está en sala', 'Sigue solo si viene por otra atención aparte'])
            ->assertMountedActionModalDontSee('Marcar que llegó a esa cita')
            // No hay a qué marcarle llegada: si se sigue, es otra atención.
            ->assertMountedActionModalSee('Tratamiento');
    }

    public function test_an_appointment_on_another_day_is_mentioned_under_the_patient(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        Appointment::factory()->for($this->busy)->for($this->cleaning)->for($rosa)->at('2026-11-04 16:00')->confirmed()->create();

        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->setActionData(['patient_id' => $rosa->id])
            ->assertMountedActionModalSee('Ya tiene cita el mié. 4 nov. a las 16:00 · Limpieza dental.')
            ->assertMountedActionModalDontSee('Ya tiene cita hoy');
    }

    public function test_a_doctor_receives_who_comes_without_an_appointment_on_their_own_agenda(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->actingAs($this->busy->user);

        // Otro doctor se desocupa antes, pero el doctor solo maneja su agenda: llega puesto él y así se queda.
        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->assertActionDataSet(['doctor_id' => $this->busy->id])
            ->setActionData(['patient_id' => $rosa->id])
            ->setActionData(['treatment_id' => $this->cleaning->id])
            ->assertActionDataSet(['doctor_id' => $this->busy->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $appointment = Appointment::query()->whereBelongsTo($rosa)->sole();

        $this->assertSame([$this->busy->id, AppointmentStatus::CheckedIn], [$appointment->doctor_id, $appointment->status]);
    }

    public function test_a_doctor_is_not_told_about_the_appointment_the_patient_has_with_someone_else(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        Appointment::factory()->for($this->busy)->for($this->cleaning)->for($rosa)->at('2026-11-02 12:00')->confirmed()->create();
        $this->actingAs($this->free->user);

        Livewire::test(Dashboard::class)
            ->mountAction('walkIn')
            ->setActionData(['patient_id' => $rosa->id])
            ->assertMountedActionModalDontSee(['Ya tiene cita', 'Marcar que llegó a esa cita'])
            ->assertMountedActionModalSee('Tratamiento');
    }
}
