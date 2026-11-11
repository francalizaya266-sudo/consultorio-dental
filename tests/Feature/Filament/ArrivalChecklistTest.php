<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Widgets\TodayAppointments;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\LabOrder;
use App\Models\MedicalBackground;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\Concerns\ReadsNotifications;
use Tests\TestCase;

class ArrivalChecklistTest extends TestCase
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
    }

    public function test_marking_arrival_says_what_to_sort_out_while_the_patient_waits(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe', 'document_type' => null, 'document_number' => null, 'account_id' => null]);
        Charge::factory()->for($rosa)->create(['amount' => 80, 'charged_at' => now()->subWeek()]);
        LabOrder::factory()->for($rosa)->create(['description' => 'Corona de zirconio', 'due_on' => '2026-11-05']);
        $appointment = $this->todayAt('10:00', $rosa);
        $this->todayAt('11:00', $this->complete());
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)
            ->callAction(TestAction::make('arrived')->table($appointment));

        $notice = $this->lastNotification();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
        $this->assertSame('Paciente en sala', $notice['title']);
        $this->assertSame(implode('<br>', [
            'Rosa Quispe',
            'Le falta: DNI, celular y cómo nos encontró.',
            'No ha llenado sus antecedentes médicos: puede hacerlo en su celular, desde Mi cuenta.',
            'Debe S/ 80.00 de antes.',
            '"Corona de zirconio" todavía no llega del laboratorio (llega el jue. 5 nov.).',
        ]), $notice['body']);
        $this->assertSame(['Completar su ficha'], array_column($notice['actions'], 'label'));
        $this->assertSame(PatientResource::getUrl('edit', ['record' => $rosa]), $notice['actions'][0]['url']);
        // Se queda en pantalla hasta que recepción la cierre.
        $this->assertSame('persistent', $notice['duration']);
    }

    public function test_a_child_without_a_guardian_on_file_is_asked_for_one(): void
    {
        $child = $this->complete(['first_name' => 'Mateo', 'last_name' => 'Ríos', 'birth_date' => '2018-03-10']);
        $appointment = $this->todayAt('10:00', $child);
        $this->todayAt('11:00', $this->complete());
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)->callAction(TestAction::make('arrived')->table($appointment));

        $this->assertSame('Mateo Ríos<br>Le falta: apoderado.', $this->lastNotification()['body']);
    }

    public function test_who_has_everything_in_order_gets_the_plain_notice_and_the_doctor_does_not_see_money(): void
    {
        $ready = $this->complete();
        $owing = $this->complete(['first_name' => 'Luis', 'last_name' => 'Mamani']);
        Charge::factory()->for($owing)->create(['amount' => 80, 'charged_at' => now()->subWeek()]);
        $first = $this->todayAt('10:00', $ready);
        $second = $this->todayAt('11:00', $owing);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)->callAction(TestAction::make('arrived')->table($first));

        $notice = $this->lastNotification();
        $this->assertSame(['Paciente en sala', null, []], [$notice['title'], $notice['body'], $notice['actions']]);

        $this->actingAs($this->doctor->user);

        Livewire::test(TodayAppointments::class)->callAction(TestAction::make('arrived')->table($second));

        $this->assertNull($this->lastNotification()['body']);
    }

    /** Paciente con todos sus datos y sus antecedentes llenos. */
    private function complete(array $attributes = []): Patient
    {
        $patient = Patient::factory()->create(['origin' => AppointmentOrigin::Recommendation, ...$attributes]);
        MedicalBackground::create(['patient_id' => $patient->id]);

        return $patient;
    }

    private function todayAt(string $time, Patient $patient): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->cleaning)->for($patient)->at('2026-11-02 '.$time)->confirmed()->create();
    }
}
