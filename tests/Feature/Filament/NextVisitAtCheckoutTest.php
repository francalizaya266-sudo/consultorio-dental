<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\Concerns\ReadsNotifications;
use Tests\TestCase;

class NextVisitAtCheckoutTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase, ReadsNotifications;

    private Treatment $cleaning;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $this->doctor = $this->doctorOffering($this->cleaning);
        $this->actingAs(User::factory()->reception()->create());
    }

    public function test_who_pays_and_has_plan_left_is_offered_the_next_session_right_there(): void
    {
        $rosa = $this->patientOwing('Rosa', 'Quispe');
        $endo = Treatment::factory()->create(['name' => 'Endodoncia', 'next_session_days' => 7]);
        $plan = TreatmentPlan::create(['patient_id' => $rosa->id, 'doctor_id' => $this->doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()->subWeek()]);
        $item = $plan->items()->create(['treatment_id' => $endo->id, 'description' => 'Endodoncia', 'tooth' => '16', 'unit_price' => 600, 'accepted_at' => now()->subWeek()]);

        $notice = $this->pay($rosa);

        $this->assertSame('Pago registrado', $notice['title']);
        $this->assertStringContainsString('Saldo de Rosa Quispe: Al día', $notice['body']);
        // Recepción ve el procedimiento, no la pieza.
        $this->assertStringContainsString('Sin próxima cita. Le toca: Endodoncia, el lunes 9 de noviembre (a la semana).', $notice['body']);
        $this->assertSame(['Agendar próxima cita', 'Ver constancia'], array_column($notice['actions'], 'label'));
        $this->assertSame(
            AppointmentResource::newUrl(['patient' => $rosa->id, 'doctor' => $this->doctor->id, 'plan_item' => $item->id, 'treatment' => $endo->id, 'date' => '2026-11-09']),
            $notice['actions'][0]['url'],
        );
        // No se cierra sola: el paciente sigue en el mostrador.
        $this->assertSame('persistent', $notice['duration']);
    }

    public function test_who_already_has_a_visit_is_told_when_and_who_has_none_is_offered_the_checkup(): void
    {
        $booked = $this->patientOwing('Luis', 'Mamani');
        Appointment::factory()->for($this->doctor)->for($this->cleaning)->for($booked)->at('2026-11-05 10:00')->confirmed()->create();

        $notice = $this->pay($booked);

        $this->assertStringContainsString('Su próxima cita: jueves 5 de noviembre, 10:00 a. m. · Limpieza dental.', $notice['body']);
        $this->assertSame(['Ver constancia'], array_column($notice['actions'], 'label'));

        // Se atendió hoy, no tiene plan ni otra cita: se le ofrece su control.
        $done = $this->patientOwing('Ana', 'Flores');
        Appointment::factory()->for($this->doctor)->for($this->cleaning)->for($done)->at('2026-11-02 07:00')->create(['status' => AppointmentStatus::Completed]);
        $done->update(['last_visit_at' => '2026-11-02 07:00']);

        $notice = $this->pay($done);

        $this->assertStringContainsString('Sin próxima cita. Su control y limpieza le toca en mayo de 2027.', $notice['body']);
        $this->assertSame(
            AppointmentResource::nextVisitUrl($done, null, CarbonImmutable::parse('2027-05-02 07:00')),
            $notice['actions'][0]['url'],
        );
    }

    private function patientOwing(string $firstName, string $lastName): Patient
    {
        $patient = Patient::factory()->create(['first_name' => $firstName, 'last_name' => $lastName]);
        Charge::factory()->for($patient)->create(['description' => 'Limpieza dental', 'amount' => 120, 'charged_at' => now()]);

        return $patient;
    }

    /**
     * Cobra lo que debe desde "Hoy" y devuelve el aviso que quedó en pantalla.
     *
     * @return array{title: string, body: string|null, duration: int|string, actions: list<array{label: string, url: string|null}>}
     */
    private function pay(Patient $patient): array
    {
        Livewire::test(Dashboard::class)
            ->mountAction('registerPayment')
            ->setActionData(['patient_id' => $patient->id])
            ->setActionData(['method' => PaymentMethod::Cash->value])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        return $this->lastNotification();
    }
}
