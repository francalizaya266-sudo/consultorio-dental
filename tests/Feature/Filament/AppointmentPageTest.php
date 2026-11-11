<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\CancelledBy;
use App\Enums\MessageTemplateKey;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Appointments\Schemas\AppointmentInfolist;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppOutbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** La página de una cita: en qué paso va, sus cuentas aparte del saldo del paciente y un historial que nunca queda vacío. */
class AppointmentPageTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $treatment;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
    }

    public function test_the_steps_show_how_far_the_visit_got_and_when_each_one_happened(): void
    {
        // Reservada el lunes a las 8:00 para el martes; llegó sin haber confirmado.
        $appointment = $this->appointment('2026-11-03 10:00', ['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => '2026-11-03 09:55']);
        $this->travelTo('2026-11-03 09:56');
        $this->actingAs(User::factory()->reception()->create());

        $steps = AppointmentInfolist::detailsData($appointment)['steps'];

        $this->assertSame(['done', 'skipped', 'current', 'todo', 'todo'], array_column($steps, 'state'));
        $this->assertSame(['2 nov. · 08:00', null, '09:55', null, null], array_column($steps, 'at'));

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSeeInOrder(['Reservada', '2 nov. · 08:00', 'Confirmada', 'No se marcó', 'Llegó', '09:55', 'En atención', 'Atendida']);
    }

    public function test_a_visit_the_patient_missed_stops_at_its_last_step_and_says_so(): void
    {
        $appointment = $this->appointment('2026-11-02 10:00', ['status' => AppointmentStatus::NoShow, 'confirmed_at' => '2026-11-02 08:00', 'no_show_at' => '2026-11-02 10:40']);
        $this->travelTo('2026-11-02 11:00');
        $this->actingAs(User::factory()->reception()->create());

        $details = AppointmentInfolist::detailsData($appointment);

        $this->assertSame(['done', 'done', 'todo', 'todo', 'todo'], array_column($details['steps'], 'state'));
        $this->assertSame(['label' => 'No asistió', 'detail' => 'Se marcó el 2 de noviembre a las 10:40.', 'color' => 'danger'], $details['closed']);
    }

    public function test_a_cancelled_visit_says_who_cancelled_it_and_why(): void
    {
        $appointment = $this->appointment('2026-11-03 10:00', ['status' => AppointmentStatus::Cancelled, 'cancelled_at' => '2026-11-02 08:00', 'cancelled_by' => CancelledBy::Patient, 'cancellation_reason' => 'Sale de viaje']);
        $this->actingAs(User::factory()->reception()->create());

        $details = AppointmentInfolist::detailsData($appointment);

        $this->assertSame(['done', 'todo', 'todo', 'todo', 'todo'], array_column($details['steps'], 'state'));
        $this->assertSame(['label' => 'Cancelada por paciente', 'detail' => '2 de noviembre a las 08:00 · Motivo: Sale de viaje', 'color' => 'gray'], $details['closed']);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSeeInOrder(['Cancelada por paciente', 'Motivo: Sale de viaje']);
    }

    public function test_a_visit_loaded_after_its_day_does_not_invent_when_it_was_booked(): void
    {
        // Atendida hace dos semanas y cargada hoy (traída de otro sistema): su fecha de registro no es la de la reserva.
        $appointment = $this->appointment('2026-10-20 10:00', [
            'status' => AppointmentStatus::Completed,
            'booked_via' => BookingChannel::Reception,
            'confirmed_at' => '2026-10-19 18:00',
            'checked_in_at' => '2026-10-20 09:55',
            'started_at' => '2026-10-20 10:00',
            'finished_at' => '2026-10-20 10:35',
        ]);
        $this->actingAs(User::factory()->reception()->create());

        $steps = AppointmentInfolist::detailsData($appointment)['steps'];
        $history = AppointmentInfolist::historyRows($appointment);

        $this->assertSame(['done', 'done', 'done', 'done', 'done'], array_column($steps, 'state'));
        $this->assertSame([null, '19 oct. · 18:00', '09:55', '10:00', '10:35'], array_column($steps, 'at'));

        // Sin cambios anotados, el historial se arma con las horas que guarda la propia cita.
        $this->assertSame(['Cita registrada', 'Confirmada', 'Llegó al consultorio', 'Pasó a atención', 'Atendida'], array_column($history, 'label'));
        $this->assertSame(['Recepción', '19 oct. 2026 · 18:00', '20 oct. 2026 · 09:55', '20 oct. 2026 · 10:00', '20 oct. 2026 · 10:35'], array_column($history, 'meta'));

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSee('Esperó 5 min · la atención duró 35 min')
            ->assertSeeInOrder(['Historial', 'Cita registrada', 'Atendida'])
            ->assertDontSee('Sin cambios registrados');
    }

    public function test_the_history_joins_changes_notices_and_payments_in_the_order_they_happened(): void
    {
        $reception = User::factory()->reception()->create(['name' => 'Rocío Paz']);
        $appointment = $this->appointment('2026-11-03 10:00');
        $outbox = app(WhatsAppOutbox::class);
        $this->actingAs($reception);

        $appointment->logEvent('created', 'staff');
        $this->travelTo('2026-11-02 08:05');
        $outbox->markSent($outbox->queueForAppointment($appointment, MessageTemplateKey::AppointmentScheduled), $reception);
        $this->travelTo('2026-11-02 09:00');
        $appointment->logEvent('confirmed', 'patient');
        $this->travelTo('2026-11-03 10:40');
        Payment::factory()->for($appointment->patient)->create(['appointment_id' => $appointment->id, 'amount' => 90, 'method' => PaymentMethod::Yape]);
        $this->travelTo('2026-11-03 10:45');
        $outbox->queueForAppointment($appointment, MessageTemplateKey::Aftercare);

        $history = AppointmentInfolist::historyRows($appointment);

        $this->assertSame([
            ['label' => 'Cita creada', 'meta' => '2 nov. 2026 · 08:00 · Rocío Paz', 'tone' => 'gray'],
            ['label' => 'Aviso enviado: cita programada por el consultorio', 'meta' => '2 nov. 2026 · 08:05 · Rocío Paz', 'tone' => 'success'],
            ['label' => 'Confirmada', 'meta' => '2 nov. 2026 · 09:00 · Paciente', 'tone' => 'success'],
            ['label' => 'Pagó S/ 90.00', 'meta' => '3 nov. 2026 · 10:40 · Yape', 'tone' => 'success'],
            ['label' => 'Aviso por enviar: cuidados después de la atención', 'meta' => '3 nov. 2026 · 10:45', 'tone' => 'warning'],
        ], $history);
    }

    public function test_the_doctor_does_not_see_payments_or_the_billing_card_of_their_visit(): void
    {
        $appointment = $this->appointment('2026-11-02 10:00', ['status' => AppointmentStatus::Completed]);
        Charge::factory()->for($appointment->patient)->create(['appointment_id' => $appointment->id, 'description' => 'Limpieza dental', 'amount' => 150]);
        Payment::factory()->for($appointment->patient)->create(['appointment_id' => $appointment->id, 'amount' => 90]);
        $this->actingAs($this->doctor->user);

        $labels = array_column(AppointmentInfolist::historyRows($appointment), 'label');

        $this->assertNotContains('Pagó S/ 90.00', $labels);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertOk()
            ->assertDontSee(['Se cargó en esta cita', 'Saldo total del paciente', 'Pagó S/ 90.00']);
    }

    public function test_the_billing_card_keeps_this_visit_apart_from_the_patients_whole_balance(): void
    {
        $appointment = $this->appointment('2026-11-02 10:00', ['status' => AppointmentStatus::Completed]);
        $patient = $appointment->patient;
        // Debe una cuota de antes; en esta cita se cargó una limpieza y dejó una parte.
        Charge::factory()->for($patient)->create(['description' => 'Ortodoncia · cuota 1 de 3', 'amount' => 400]);
        Charge::factory()->for($patient)->create(['appointment_id' => $appointment->id, 'description' => 'Limpieza dental', 'amount' => 150]);
        Payment::factory()->for($patient)->create(['appointment_id' => $appointment->id, 'amount' => 90, 'method' => PaymentMethod::Yape]);
        $this->actingAs(User::factory()->reception()->create());

        $billing = AppointmentInfolist::billingData($appointment);

        $this->assertSame([150.0, 90.0, 60.0, 60, 460.0], [$billing['charged'], $billing['paid'], $billing['due'], $billing['percent'], $billing['balance']]);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSeeInOrder(['Se cargó en esta cita', 'S/ 150.00', 'Pagó en esta cita', 'S/ 90.00', 'Falta de esta cita', 'S/ 60.00', 'Saldo total del paciente: Debe S/ 460.00']);
    }

    public function test_a_visit_shows_as_paid_once_the_patient_settles_the_account_another_day(): void
    {
        $appointment = $this->appointment('2026-11-02 10:00', ['status' => AppointmentStatus::Completed]);
        $patient = $appointment->patient;
        Charge::factory()->for($patient)->create(['appointment_id' => $appointment->id, 'description' => 'Limpieza dental', 'amount' => 150]);
        Payment::factory()->for($patient)->create(['appointment_id' => $appointment->id, 'amount' => 90]);
        // Días después paga lo que faltaba en caja, sin abrir la cita.
        Payment::factory()->for($patient)->create(['amount' => 60, 'paid_at' => '2026-11-05 16:00']);
        $this->actingAs(User::factory()->reception()->create());

        $billing = AppointmentInfolist::billingData($appointment);

        $this->assertSame([0.0, 100, 'Al día'], [$billing['due'], $billing['percent'], $billing['balance_label']]);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSeeInOrder(['Esta cita', 'Pagada', 'Saldo total del paciente: Al día'])
            ->assertDontSee('Falta de esta cita');
    }

    public function test_the_notes_window_saves_with_a_button_that_says_guardar(): void
    {
        $appointment = $this->appointment('2026-11-03 10:00');
        $this->actingAs(User::factory()->reception()->create());

        $page = Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])->mountAction('notes');

        // No se envía nada a nadie: el botón de una ventana que guarda dice "Guardar".
        $this->assertSame('Guardar', $page->instance()->getMountedAction()->getModalSubmitAction()->getLabel());

        $page->setActionData(['internal_notes' => 'Prefiere la tarde'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('Prefiere la tarde', $appointment->fresh()->internal_notes);
    }

    /** Una cita con el doctor de la prueba, reservada "ahora" (lunes 8:00) salvo que se diga otra cosa. */
    private function appointment(string $startsAt, array $attributes = []): Appointment
    {
        $this->treatment ??= Treatment::factory()->create(['name' => 'Limpieza dental']);
        $this->doctor ??= $this->doctorOffering($this->treatment);

        return Appointment::factory()->for($this->doctor)->for($this->treatment)->for(Patient::factory())->at($startsAt)->create($attributes);
    }
}
