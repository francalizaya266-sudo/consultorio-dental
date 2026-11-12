<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\RescheduleRequests\Pages\ListRescheduleRequests;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\RescheduleRequest;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Booking\RescheduleRequestService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Solicitudes de cambio en el panel: qué se ofrece cuando los horarios del paciente ya no sirven y cómo se cierran solas. */
class RescheduleRequestsPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        $this->treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $this->doctor = $this->doctorOffering($this->treatment);
    }

    /** @param  list<string>  $proposed */
    private function requestFor(Appointment $appointment, array $proposed): RescheduleRequest
    {
        return app(RescheduleRequestService::class)->requestByPatient(
            $appointment,
            array_map(fn (string $time) => CarbonImmutable::parse($time), $proposed),
        );
    }

    private function appointmentAt(string $startsAt): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->treatment)->at($startsAt)->confirmed()->create();
    }

    public function test_when_none_of_the_proposed_times_is_left_the_row_offers_another_time_instead_of_approving(): void
    {
        $appointment = $this->appointmentAt('2026-11-03 10:00');
        $request = $this->requestFor($appointment, ['2026-11-04 10:00', '2026-11-05 16:00']);
        // Después de pedirlo, otro paciente tomó el miércoles y el doctor bloqueó la tarde del jueves.
        $this->appointmentAt('2026-11-04 10:00');
        $this->doctor->scheduleBlocks()->create(['type' => 'other', 'starts_at' => '2026-11-05 15:00', 'ends_at' => '2026-11-05 19:00']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListRescheduleRequests::class)
            ->assertSee(['Mié. 04/11 10:00 (ya está ocupado)', 'Jue. 05/11 16:00 (ya está ocupado)'])
            ->assertActionHidden(TestAction::make('approve')->table($request))
            ->assertActionVisible(TestAction::make('offerAnotherTime')->table($request))
            ->assertActionVisible(TestAction::make('reject')->table($request))
            ->callAction(TestAction::make('offerAnotherTime')->table($request), ['doctor_id' => $this->doctor->id, 'date' => '2026-11-06', 'slot' => '09:00'])
            ->assertHasNoFormErrors()
            ->assertNotified('Cita movida. El aviso para que confirme está listo en Avisos.');

        $this->assertSame('2026-11-06 09:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertSame(AppointmentStatus::Pending, $appointment->fresh()->status);
        $this->assertSame(RescheduleRequestStatus::Approved, $request->fresh()->status);
        $this->assertNull(RescheduleRequestResource::getNavigationBadge());
    }

    public function test_a_proposed_time_says_why_it_no_longer_works(): void
    {
        $appointment = $this->appointmentAt('2026-11-05 10:00');
        // El horario del doctor se acortó después de que el paciente propuso el sábado en la tarde.
        $request = $this->requestFor($appointment, ['2026-11-03 10:00', '2026-11-07 18:00']);
        $this->doctor->schedules()->where('weekday', 6)->where('starts_at', '>=', '15:00')->delete();

        $this->travelTo('2026-11-03 12:00');
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListRescheduleRequests::class)
            ->assertSee(['Mar. 03/11 10:00 (ya pasó)', 'Sáb. 07/11 18:00 (fuera del horario de atención)'])
            ->assertActionVisible(TestAction::make('offerAnotherTime')->table($request));
    }

    public function test_with_a_time_that_still_works_approving_stays_first_and_the_other_answers_go_in_the_menu(): void
    {
        $request = $this->requestFor($this->appointmentAt('2026-11-03 10:00'), ['2026-11-04 10:00']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListRescheduleRequests::class)
            ->assertDontSee('(ya')
            ->assertActionVisible(TestAction::make('approve')->table($request))
            ->assertActionHidden(TestAction::make('offerAnotherTime')->table($request))
            ->assertActionVisible(TestAction::make('offerAnotherTimeInstead')->table($request))
            ->assertActionVisible(TestAction::make('reject')->table($request));
    }

    public function test_a_request_closed_by_moving_the_appointment_leaves_the_pending_list_and_says_why(): void
    {
        $appointment = $this->appointmentAt('2026-11-03 10:00');
        $request = $this->requestFor($appointment, ['2026-11-04 10:00']);
        $reception = User::factory()->reception()->create();
        $this->actingAs($reception);

        Livewire::test(Agenda::class)->set('date', '2026-11-03')->assertSee('Pidió cambio');

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->callAction('move', ['doctor_id' => $this->doctor->id, 'date' => '2026-11-05', 'slot' => '16:00', 'agreed' => true])
            ->assertHasNoFormErrors();

        $this->assertNull(RescheduleRequestResource::getNavigationBadge());
        Livewire::test(Agenda::class)->set('date', '2026-11-05')->assertDontSee('Pidió cambio');

        Livewire::test(ListRescheduleRequests::class)
            ->assertCanNotSeeTableRecords([$request])
            ->set('activeTab', 'resolved')
            ->assertCanSeeTableRecords([$request])
            ->assertSee(['Cerrada', $reception->name, 'La cita se movió a otro horario.']);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSee('Solicitud de cambio cerrada');
    }
}
