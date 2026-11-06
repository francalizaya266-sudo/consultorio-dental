<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Schemas\ConsultationForm;
use App\Models\Appointment;
use App\Models\Consent;
use App\Models\ConsentTemplate;
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

/** La pantalla de Atender: la barra que hace de encabezado, los antecedentes sin salir y la receta segura. */
class AttendScreenTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
    }

    public function test_the_bar_is_the_header_and_has_the_only_finish_button(): void
    {
        [$doctor, $consultation] = $this->consultation(['first_name' => 'Laura', 'last_name' => 'Zayas', 'birth_date' => now()->subYears(46)]);
        $this->actingAs($doctor->user);

        $page = Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSeeHtml('<h1 class="cb-name">Laura Zayas</h1>')
            ->assertSee(['46 años', $consultation->appointment->treatment->name, 'Se guarda solo'])
            ->assertSeeInOrder(['Antes de empezar', 'Motivo y examen', 'Radiografías y fotos', 'Lo que hiciste', 'Odontograma', 'Diagnóstico', 'Tratamientos y cobro', 'Nota de evolución', 'Para el paciente', 'Indicaciones y receta']);

        $this->assertSame(1, substr_count($page->html(), 'Terminar atención'));

        // Lo que estaba arriba ahora se abre desde la barra.
        $page->mountAction('template')->assertActionMounted('template')->call('unmountAction')
            ->mountAction('signConsent')->assertActionMounted('signConsent')->call('unmountAction')
            ->mountAction('editBackground')->assertActionMounted('editBackground');
    }

    public function test_the_background_is_registered_without_leaving_and_the_prescription_warns_until_then(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $this->actingAs($doctor->user);

        $page = Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['Faltan los antecedentes médicos', 'Sin antecedentes · registrar'])
            ->assertDontSee('No sabemos si tiene alergias')
            ->fillForm(['prescription_items' => [['medication' => 'Ibuprofeno 400 mg', 'instructions' => '1 cada 8 horas']]])
            ->assertSee('No sabemos si tiene alergias')
            ->mountAction(TestAction::make('printPrescription')->schemaComponent('prescription'))
            ->assertMountedActionModalSee('¿Imprimir sin antecedentes?')
            ->call('unmountAction');

        $page->callAction('editBackground', data: ['allergies' => 'Ninguna', 'medications' => 'Ninguna'])
            ->assertHasNoActionErrors()
            ->assertNotified('Antecedentes guardados')
            ->assertSee('Sin alergias ni enfermedades')
            ->assertDontSee(['Faltan los antecedentes médicos', 'No sabemos si tiene alergias']);

        $this->assertSame('Ninguna', $consultation->patient->fresh()->medicalBackground->allergies);
    }

    public function test_what_the_patient_wrote_when_booking_can_become_the_reason(): void
    {
        [$doctor, $consultation] = $this->consultation(appointment: ['patient_note' => 'Me duele una muela al masticar']);
        $consultation->update(['chief_complaint' => null]);
        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['Al reservar escribió', '«Me duele una muela al masticar»'])
            ->callAction(TestAction::make('usePatientNote')->schemaComponent('patientNote'))
            ->assertSchemaStateSet(['chief_complaint' => 'Me duele una muela al masticar'])
            ->assertDontSee('Al reservar escribió');
    }

    public function test_the_bar_says_whether_the_consent_of_the_treatment_is_signed(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $treatment = $consultation->appointment->treatment;
        $this->actingAs($doctor->user);

        // Sin consentimiento propio del tratamiento, no se dice nada.
        $this->assertNull(ConsultationForm::consentStatus($consultation));

        $template = ConsentTemplate::create(['name' => 'Consentimiento de extracción', 'treatment_id' => $treatment->id, 'body' => 'Extracción de {paciente}.']);
        $this->assertFalse(ConsultationForm::consentStatus($consultation));
        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])->assertSee('Falta firmar el consentimiento');

        // Uno firmado antes de esta cita no vale para esta.
        $old = $this->signedConsent($consultation->patient, $template, now()->subMonths(3));
        $this->assertFalse(ConsultationForm::consentStatus($consultation));

        $this->signedConsent($consultation->patient, $template, now());
        $this->assertTrue(ConsultationForm::consentStatus($consultation));
        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee('Consentimiento firmado')
            ->assertDontSee('Falta firmar el consentimiento');

        $old->delete();
        Consent::query()->update(['revoked_at' => now()]);
        $this->assertFalse(ConsultationForm::consentStatus($consultation));
    }

    public function test_the_bar_marks_the_sections_that_already_have_something(): void
    {
        [, $consultation] = $this->consultation();

        $this->assertSame([], ConsultationForm::filledSections($consultation, [
            'chief_complaint' => ' ', 'vital_signs' => ['blood_pressure' => null], 'diagnoses' => [], 'treatments' => [], 'planItems' => [], 'prescription_items' => [['medication' => '']],
        ]));

        $this->assertSame(['motivo', 'diagnostico', 'plan', 'indicaciones'], ConsultationForm::filledSections($consultation, [
            'vital_signs' => ['blood_pressure' => '120/80'],
            'diagnoses' => ['record-1' => ['icd10_code_id' => 1]],
            'planItems' => ['3'],
            'prescription_items' => [['medication' => 'Paracetamol 500 mg']],
        ]));
    }

    public function test_vitals_and_diagnosis_notes_stay_folded_until_needed(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee('Para cirugías, urgencias o pacientes con presión alta')
            ->assertDontSee('Notas del diagnóstico')
            ->callAction(TestAction::make('addDiagnosisNotes')->schemaComponent('diagnosis'))
            ->assertSee('Notas del diagnóstico');

        $consultation->patient->medicalBackground()->create(['conditions' => ['hypertension'], 'allergies' => 'Ninguna']);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee('Tómalos antes de empezar: tiene hipertensión.');
    }

    /**
     * @param  array<string, mixed>  $patient
     * @param  array<string, mixed>  $appointment
     * @return array{0: Doctor, 1: Consultation}
     */
    private function consultation(array $patient = [], array $appointment = []): array
    {
        $treatment = Treatment::factory()->create(['name' => 'Extracción simple']);
        $doctor = $this->doctorOffering($treatment);
        $booked = Appointment::factory()
            ->for($doctor)
            ->for($treatment)
            ->for(Patient::factory()->create($patient))
            ->at('2026-11-02 10:00')
            ->confirmed()
            ->create(['created_at' => now()->subWeek(), ...$appointment]);

        return [$doctor, app(ConsultationService::class)->startFromAppointment($booked, $doctor->user)];
    }

    private function signedConsent(Patient $patient, ConsentTemplate $template, mixed $at): Consent
    {
        return Consent::create([
            'patient_id' => $patient->id,
            'consent_template_id' => $template->id,
            'treatment_id' => $template->treatment_id,
            'title' => $template->name,
            'body_snapshot' => 'Firmado.',
            'signer_name' => $patient->full_name,
            'signed_at' => $at,
        ]);
    }
}
