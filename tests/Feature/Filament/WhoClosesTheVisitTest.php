<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Filament\Widgets\TodayAppointments;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class WhoClosesTheVisitTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $cleaning;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->cleaning);
        $this->doctor->update(['name' => 'Dra. Elena Ríos']);
    }

    public function test_while_the_patient_is_with_the_doctor_reception_closes_only_as_an_exception(): void
    {
        $appointment = $this->inRoomAt('10:00');
        $this->todayAt('16:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:10', 'America/Lima'));
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)
            // Recepción no atiende: anota que pasó con el doctor.
            ->assertActionHasLabel(TestAction::make('start')->table($appointment), 'Pasó a consulta')
            ->assertActionHidden(TestAction::make('complete')->table($appointment))
            ->assertActionVisible(TestAction::make('closeWithoutDoctor')->table($appointment))
            ->mountAction(TestAction::make('closeWithoutDoctor')->table($appointment))
            ->assertMountedActionModalSee(['¿Cerrar la cita sin la atención del doctor?', 'Lo normal es que Dra. Elena Ríos la cierre al terminar.', 'Limpieza dental', 'Queda sin historia clínica escrita.'])
            ->callMountedAction()
            ->assertNotified('Cita atendida');

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
    }

    public function test_once_the_doctor_opened_the_attention_only_the_doctor_closes_it(): void
    {
        $appointment = $this->inRoomAt('10:00', AppointmentStatus::InProgress);
        $this->todayAt('16:00');
        Consultation::factory()->for($this->doctor)->for($appointment->patient)->create(['appointment_id' => $appointment->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 11:30', 'America/Lima'));
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)
            ->assertActionHidden(TestAction::make('complete')->table($appointment))
            ->assertActionHidden(TestAction::make('closeWithoutDoctor')->table($appointment));
    }

    public function test_a_visit_nobody_closed_after_its_time_is_closed_by_reception_after_confirming(): void
    {
        $appointment = $this->inRoomAt('10:00');
        $this->todayAt('16:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 11:30', 'America/Lima'));
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)
            ->assertActionHidden(TestAction::make('closeWithoutDoctor')->table($appointment))
            ->mountAction(TestAction::make('complete')->table($appointment))
            ->assertMountedActionModalSee(['¿Cerrar la cita sin la atención del doctor?', 'Queda sin historia clínica escrita.'])
            ->callMountedAction()
            ->assertNotified('Cita atendida');

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
    }

    public function test_when_the_doctor_does_not_use_the_panel_reception_closes_as_always(): void
    {
        $this->doctor->update(['user_id' => null]);
        $appointment = $this->inRoomAt('10:00');
        $this->todayAt('16:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:10', 'America/Lima'));
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)
            ->assertActionHidden(TestAction::make('closeWithoutDoctor')->table($appointment))
            ->callAction(TestAction::make('complete')->table($appointment))
            ->assertNotified('Cita atendida');

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
    }

    private function inRoomAt(string $time, AppointmentStatus $status = AppointmentStatus::CheckedIn): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->cleaning)->at('2026-11-02 '.$time)
            ->create(['status' => $status, 'checked_in_at' => '2026-11-02 '.$time]);
    }

    private function todayAt(string $time): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->cleaning)->at('2026-11-02 '.$time)->confirmed()->create();
    }
}
