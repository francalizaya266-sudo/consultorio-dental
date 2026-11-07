<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Schemas\ConsultationForm;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Clinical\ConsultationService;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Atender cambia según el paciente y la cita: un niño, un control, una cita sin cita, los riesgos del día. */
class AttendByVisitTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
    }

    public function test_a_child_gets_syrups_by_weight_and_not_the_adult_prescription_of_the_template(): void
    {
        [$doctor, $consultation] = $this->attend('extraccion-simple', ['birth_date' => now()->subYears(7)->subMonth()]);
        $this->actingAs($doctor->user);

        // La plantilla de extracción receta Ibuprofeno 400 mg: a un niño no se le copia.
        $this->assertNull($consultation->prescription);

        $childSyrup = TestAction::make('addChildMedicationIbuprofen')->schemaComponent('prescription.child.childMedications');

        $page = Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee('Tiene 7 años: la dosis va por su peso')
            ->callAction($childSyrup)
            ->assertNotified('Primero anota su peso: la dosis se calcula con él.')
            ->fillForm(['vital_signs' => ['weight' => '20']])
            ->callAction($childSyrup)
            ->call('save')
            ->assertHasNoFormErrors();

        $item = $consultation->fresh()->prescription->items()->sole();
        $this->assertSame('Ibuprofeno suspensión 100 mg/5 ml', $item->medication);
        $this->assertStringStartsWith('10 ml (200 mg) cada 8 horas por 3 días', $item->instructions);
        $this->assertSame('Peso 20 kg', $consultation->fresh()->vitalsSummary());

        // Sin el peso no se puede revisar la dosis.
        $page->fillForm(['vital_signs' => ['weight' => null]])->assertSee('Falta su peso');
    }

    public function test_the_prescription_warns_about_a_pregnancy_and_the_risks_show_before_starting(): void
    {
        [$doctor, $consultation] = $this->attend('extraccion-simple');
        $consultation->patient->medicalBackground()->create(['allergies' => 'Ninguna', 'medications' => 'Warfarina 5 mg', 'is_pregnant' => true, 'pregnancy_weeks' => 24]);
        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['Revisa antes de empezar', 'Anticoagulantes o coagulación: que no los deje sin hablar con su médico.'])
            ->fillForm(['prescription_items' => [['medication' => 'Ibuprofeno 400 mg', 'instructions' => '1 cada 8 horas']]])
            ->assertSee('Cuidado con Ibuprofeno: está embarazada de 24 semanas, y desde la semana 20 se evita; aumenta el sangrado con anticoagulantes o problemas de coagulación.');
    }

    public function test_an_orthodontic_control_folds_the_reason_hides_the_odontogram_and_asks_for_the_archwire(): void
    {
        [$doctor, $consultation] = $this->attend('control-de-ortodoncia');
        $this->actingAs($doctor->user);

        $this->assertSame('Control de ortodoncia.', $consultation->chief_complaint);
        $this->assertTrue(ConsultationForm::foldsReason($consultation));
        $this->assertTrue(ConsultationForm::hidesOdontogram($consultation));

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['Es un control: pregúntale cómo le ha ido', 'Cita de control: anota solo si cuenta alguna molestia o algo nuevo.', 'Arco', 'Ligas o cadenetas'])
            ->assertDontSee('Cada toque se guarda solo')
            ->fillForm(['procedure_details' => ['archwire' => 'NiTi 0.016', 'hygiene' => 'good']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Arco NiTi 0.016 · Higiene buena', $consultation->fresh()->procedureSummary());
    }

    public function test_the_last_visit_brings_its_resin_shade_and_its_prescription_without_what_no_longer_suits(): void
    {
        [$doctor, $consultation] = $this->attend('curacion-con-resina');
        $patient = $consultation->patient;
        $before = Consultation::factory()->for($patient)->for($doctor)->create(['attended_at' => '2026-10-12 10:00', 'procedure_details' => ['tooth' => '36', 'shade' => 'A2']]);
        $before->prescriptions()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'issued_at' => '2026-10-12 10:30'])
            ->items()->createMany([['medication' => 'Amoxicilina 500 mg', 'instructions' => '1 cada 8 horas por 7 días'], ['medication' => 'Paracetamol 500 mg', 'instructions' => '1 cada 8 horas si hay dolor']]);
        // Desde entonces se supo que es alérgica a la penicilina.
        $patient->medicalBackground()->create(['allergies' => 'Penicilina']);
        $this->actingAs($doctor->user);

        $page = Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['La última: A2 · pieza 36 · 12 oct. 2026', 'Repetir la del 12/10: Amoxicilina 500 mg, Paracetamol 500 mg'])
            ->callAction(TestAction::make('useLastShade')->schemaComponent('procedureDetails.procedure_details.shade'))
            ->assertSchemaStateSet(['procedure_details.shade' => 'A2'])
            ->callAction(TestAction::make('repeatPrescription')->schemaComponent('prescription'))
            ->assertNotified('La receta anterior traía un medicamento que no le conviene')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('A2', $consultation->fresh()->procedure_details['shade']);
        $this->assertSame(['Paracetamol 500 mg'], $consultation->fresh()->prescription->items->pluck('medication')->all());
        // Ya está todo lo que se podía repetir: el botón no vuelve a ofrecerlo.
        $page->assertDontSee('Repetir la del 12/10');
    }

    public function test_without_an_appointment_it_cannot_finish_without_a_reason(): void
    {
        $treatment = Treatment::where('slug', 'curacion-con-resina')->firstOrFail();
        $doctor = $this->doctorOffering($treatment);
        $consultation = app(ConsultationService::class)->startWithoutAppointment(Patient::factory()->create(), $doctor->user);
        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['Sin cita o de urgencia', 'Sin motivo no se puede terminar la atención.', 'De 0 (nada) a 10'])
            ->callAction('close')
            ->assertNotified('Falta el motivo');

        $this->assertNull($consultation->fresh()->closed_at);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->fillForm(['chief_complaint' => 'Dolor fuerte en 46 desde ayer', 'vital_signs' => ['pain' => '8']])
            ->callAction('close');

        $this->assertNotNull($consultation->fresh()->closed_at);
        $this->assertSame('Dolor 8/10', $consultation->fresh()->vitalsSummary());
    }

    /**
     * @param  array<string, mixed>  $patient
     * @return array{0: Doctor, 1: Consultation}
     */
    private function attend(string $slug, array $patient = []): array
    {
        $treatment = Treatment::where('slug', $slug)->firstOrFail();
        $doctor = $this->doctorOffering($treatment);
        $booked = Appointment::factory()
            ->for($doctor)
            ->for($treatment)
            ->for(Patient::factory()->create($patient))
            ->at('2026-11-02 10:00')
            ->confirmed()
            ->create();

        return [$doctor, app(ConsultationService::class)->startFromAppointment($booked, $doctor->user)];
    }
}
