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
        Appointment::factory()->for($this->busy)->for($this->cleaning)->at('2026-11-02 10:00')->confirmed()->create();
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
}
