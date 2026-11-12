<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Agendar o mover una cita a una hora que ya pasó: se pregunta antes y no se le prepara aviso al paciente. */
class PastHourBookingTest extends TestCase
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
        // Lunes 2 de noviembre, 3:40 de la tarde.
        $this->travelTo('2026-11-02 15:40');
        $this->actingAs(User::factory()->reception()->create());
    }

    public function test_booking_with_another_time_that_already_passed_asks_before_saving(): void
    {
        $form = [
            'patient_id' => Patient::factory()->create()->id,
            'doctor_id' => $this->doctor->id,
            'treatment_id' => $this->treatment->id,
            'date' => '2026-11-02',
            'manual' => true,
            'manual_time' => '09:00',
        ];

        Livewire::test(CreateAppointment::class)
            ->fillForm($form)
            ->assertSee('Esa hora ya pasó: registrar la cita igual')
            ->call('create')
            ->assertHasFormErrors(['past_time_ok']);

        $this->assertSame(0, Appointment::count());

        Livewire::test(CreateAppointment::class)
            ->fillForm([...$form, 'past_time_ok' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Cita registrada en una hora que ya pasó. No se preparó aviso al paciente.');

        $this->assertSame('2026-11-02 09:00', Appointment::sole()->starts_at->format('Y-m-d H:i'));
        $this->assertSame(0, WhatsappMessage::count(), 'No se le anuncia al paciente una cita que ya pasó.');
    }

    public function test_another_time_later_today_does_not_ask_and_prepares_the_notice(): void
    {
        Livewire::test(Agenda::class)
            ->callAction('createAppointment', [
                'patient_id' => Patient::factory()->create()->id,
                'doctor_id' => $this->doctor->id,
                'treatment_id' => $this->treatment->id,
                'date' => '2026-11-02',
                'manual' => true,
                'manual_time' => '18:00',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Cita creada. El aviso al paciente está listo en Avisos.');

        $this->assertSame(MessageTemplateKey::AppointmentScheduled, WhatsappMessage::sole()->template_key);
    }

    public function test_moving_a_visit_to_an_hour_that_already_passed_asks_and_leaves_no_notice(): void
    {
        $appointment = Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-02 17:00')->confirmed()->create();

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->callAction('move', ['doctor_id' => $this->doctor->id, 'date' => '2026-11-02', 'manual' => true, 'manual_time' => '10:00'])
            ->assertHasFormErrors(['past_time_ok']);

        $this->assertSame('17:00', $appointment->fresh()->starts_at->format('H:i'));

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->callAction('move', ['doctor_id' => $this->doctor->id, 'date' => '2026-11-02', 'manual' => true, 'manual_time' => '10:00', 'past_time_ok' => true])
            ->assertHasNoFormErrors()
            ->assertNotified('Cita movida a una hora que ya pasó. No se preparó aviso al paciente.');

        $appointment->refresh();
        $this->assertSame('10:00', $appointment->starts_at->format('H:i'));
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status, 'No queda esperando una confirmación que ya no tiene sentido.');
        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_dropping_a_visit_on_an_hour_that_already_passed_warns_before_moving(): void
    {
        $appointment = Appointment::factory()->for($this->doctor)->for($this->treatment)->at('2026-11-02 17:00')->confirmed()->create();
        $target = ['id' => $appointment->id, 'column' => "{$this->doctor->id}|2026-11-02", 'time' => '10:00'];

        Livewire::test(Agenda::class)
            ->mountAction('move', arguments: $target)
            ->assertMountedActionModalSee(['¿Mover la cita a una hora que ya pasó?', 'no se le prepara aviso al paciente', 'Mover igual'])
            ->assertMountedActionModalDontSee('Ya lo acordé con el paciente')
            ->callMountedAction()
            ->assertNotified('Cita movida a una hora que ya pasó. No se preparó aviso al paciente.');

        $this->assertSame('10:00', $appointment->fresh()->starts_at->format('H:i'));
        $this->assertSame(0, WhatsappMessage::count());

        // Más tarde hoy: la pregunta de siempre.
        Livewire::test(Agenda::class)
            ->mountAction('move', arguments: [...$target, 'time' => '18:00'])
            ->assertMountedActionModalSee(['¿Mover la cita?', 'Ya lo acordé con el paciente'])
            ->assertMountedActionModalDontSee('ya pasó');
    }
}
