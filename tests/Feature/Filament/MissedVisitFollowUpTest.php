<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Widgets\FollowUpsToday;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class MissedVisitFollowUpTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $cleaning;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $this->doctor = $this->doctorOffering($this->cleaning);
    }

    public function test_who_missed_or_cancelled_and_has_no_other_visit_is_chased_from_today(): void
    {
        $noShow = $this->patient('Rosa', 'Quispe');
        $this->visit($noShow, '2026-10-27 10:00', AppointmentStatus::NoShow);

        $cancelled = $this->patient('Luis', 'Mamani');
        $this->visit($cancelled, '2026-11-05 10:00', AppointmentStatus::Cancelled);

        // Faltó, pero después vino: ya no hay nada que recuperar.
        $cameBack = $this->patient('Ana', 'Flores');
        $this->visit($cameBack, '2026-10-20 10:00', AppointmentStatus::NoShow);
        $this->visit($cameBack, '2026-10-28 10:00', AppointmentStatus::Completed);

        // Canceló y ya tiene otra cita.
        $rebooked = $this->patient('Pedro', 'Huamán');
        $this->visit($rebooked, '2026-10-29 10:00', AppointmentStatus::Cancelled);
        $this->visit($rebooked, '2026-11-06 10:00', AppointmentStatus::Confirmed);

        // Faltó hace más de dos meses: se le llama por su control, no por esa cita.
        $longAgo = $this->patient('Carmen', 'Rojas');
        $this->visit($longAgo, '2026-08-20 10:00', AppointmentStatus::NoShow);

        // Dejó un plan a medias: ya sale en "Planes sin próxima cita".
        $withPlan = $this->patient('Elena', 'Vargas');
        $this->visit($withPlan, '2026-10-27 11:00', AppointmentStatus::NoShow);
        $plan = TreatmentPlan::create(['patient_id' => $withPlan->id, 'doctor_id' => $this->doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()->subWeek()]);
        $plan->items()->create(['treatment_id' => $this->cleaning->id, 'description' => 'Limpieza', 'unit_price' => 100, 'accepted_at' => now()->subWeek()]);

        $this->assertEqualsCanonicalizing([$noShow->id, $cancelled->id], Patient::query()->missedAndNotRebooked()->pluck('id')->all());

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(FollowUpsToday::class)
            ->assertSeeInOrder(['Faltaron o cancelaron', 'En los últimos 2 meses y siguen sin otra cita', 'Luis Mamani', 'Se canceló su cita del 5 de noviembre · Limpieza dental', 'Rosa Quispe', 'No asistió el 27 de octubre · Limpieza dental'])
            ->assertSee(AppointmentResource::getUrl('create', ['patient' => $noShow->id, 'treatment' => $this->cleaning->id, 'doctor' => $this->doctor->id]))
            ->assertDontSee(['Ana Flores', 'Pedro Huamán', 'Carmen Rojas'])
            ->mountAction('whatsapp', ['template' => MessageTemplateKey::MissedVisit->value, 'patient' => $noShow->id])
            ->assertSchemaStateSet(['body' => "Hola Rosa, te escribimos de Clínica Vega Implant.\nNo pudimos verte en tu cita de limpieza dental del martes 27 de octubre. ¿Te reservamos otro horario? Respóndenos con el día y la hora que te acomodan."])
            ->callMountedAction()
            ->assertHasNoErrors();

        Livewire::test(FollowUpsToday::class)->assertSee('Le escribiste hoy');

        // La lista completa está en Pacientes, con el mismo número.
        Livewire::withQueryParams(['tab' => 'missed'])
            ->test(ListPatients::class)
            ->assertCanSeeTableRecords([$noShow, $cancelled])
            ->assertCanNotSeeTableRecords([$cameBack, $rebooked, $longAgo, $withPlan]);
    }

    public function test_the_file_of_who_missed_offers_to_book_the_same_again(): void
    {
        $rosa = $this->patient('Rosa', 'Quispe');
        $this->visit($rosa, '2026-10-27 10:00', AppointmentStatus::NoShow);
        $this->patient('Luis', 'Mamani');

        $this->actingAs(User::factory()->reception()->create());

        $this->get(PatientResource::getUrl('view', ['record' => $rosa]))
            ->assertOk()
            ->assertSeeInOrder(['Siguiente paso', 'Faltó a su cita del 27 de octubre', 'Limpieza dental · sigue sin otra cita', 'Agendar'])
            ->assertSee(AppointmentResource::getUrl('create', ['patient' => $rosa->id, 'treatment' => $this->cleaning->id, 'doctor' => $this->doctor->id]));

        // Con la cita nueva, el paso desaparece.
        $this->visit($rosa, '2026-11-04 10:00', AppointmentStatus::Confirmed);

        $this->get(PatientResource::getUrl('view', ['record' => $rosa]))->assertOk()->assertDontSee('Faltó a su cita');
    }

    private function patient(string $firstName, string $lastName): Patient
    {
        // Con fecha fija: si a alguno le toca cumplir años ese lunes, sale en "Cumpleaños de hoy".
        return Patient::factory()->create(['first_name' => $firstName, 'last_name' => $lastName, 'birth_date' => '1990-05-15']);
    }

    private function visit(Patient $patient, string $when, AppointmentStatus $status): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($this->cleaning)->for($patient)->at($when)->create(['status' => $status]);
    }
}
