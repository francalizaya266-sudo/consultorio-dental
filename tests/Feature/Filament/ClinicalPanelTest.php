<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\SnippetField;
use App\Enums\ToothState;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Pages\ListConsultations;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Resources\Patients\Pages\ViewClinicalRecord;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Widgets\TodayAppointments;
use App\Livewire\OdontogramChart;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Icd10Code;
use App\Models\Patient;
use App\Models\TextSnippet;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Clinical\ConsultationService;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class ClinicalPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
    }

    public function test_only_doctors_and_dentist_admins_see_the_clinical_record(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);
        $patient = $appointment->patient;

        $allowed = [$doctor->user, User::factory()->admin(isDentist: true)->create()];
        $denied = [User::factory()->reception()->create(), User::factory()->admin()->create()];

        foreach ($allowed as $user) {
            $this->actingAs($user);
            $this->get(PatientResource::getUrl('clinical', ['record' => $patient]))->assertOk()->assertSee('Antecedentes médicos');
            $this->get(ConsultationResource::getUrl('view', ['record' => $consultation]))->assertOk();
            $this->get(ConsultationResource::getUrl('index'))->assertOk();
        }

        foreach ($denied as $user) {
            $this->actingAs($user);
            $this->get(PatientResource::getUrl('clinical', ['record' => $patient]))->assertForbidden();
            $this->get(ConsultationResource::getUrl('index'))->assertForbidden();
            $this->assertContains($this->get(ConsultationResource::getUrl('view', ['record' => $consultation]))->status(), [403, 404]);
        }

        // Otro doctor que no atiende a este paciente no ve su historia.
        $this->actingAs($this->doctorOffering(Treatment::factory()->create())->user);
        $this->get(PatientResource::getUrl('clinical', ['record' => $patient]))->assertNotFound();
        $this->get(ConsultationResource::getUrl('view', ['record' => $consultation]))->assertNotFound();
    }

    public function test_the_doctor_attends_writes_and_finishes_a_consultation(): void
    {
        [$doctor, $appointment] = $this->appointmentToday('limpieza-dental');
        $this->actingAs($doctor->user);

        Livewire::test(TodayAppointments::class)
            ->callAction(TestAction::make('start')->table($appointment))
            ->assertRedirect(ConsultationResource::getUrl('edit', ['record' => Consultation::sole()]));

        $consultation = Consultation::sole();
        $code = Icd10Code::where('code', 'K03.6')->firstOrFail();

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSchemaStateSet(['chief_complaint' => 'Acude para control y limpieza dental.'])
            ->assertSee('Faltan los antecedentes médicos')
            ->fillForm([
                'clinical_exam' => 'Cálculo supragingival en sector anteroinferior.',
                'vital_signs.blood_pressure' => '120/80',
                'diagnoses' => [['icd10_code_id' => $code->id, 'tooth' => null, 'type' => 'definitive']],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction('close')
            ->assertRedirect(ConsultationResource::getUrl('view', ['record' => $consultation]));

        $consultation->refresh();
        $this->assertNotNull($consultation->closed_at);
        $this->assertSame('120/80', $consultation->vital_signs['blood_pressure']);
        $this->assertSame('K03.6', $consultation->diagnoses->sole()->icd10Code->code);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);

        // Cerrada: el enlace de edición lleva a la lectura, y se puede reabrir.
        $this->get(ConsultationResource::getUrl('edit', ['record' => $consultation]))->assertRedirect(ConsultationResource::getUrl('view', ['record' => $consultation]));
        Livewire::test(ViewConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee('K03.6')
            ->callAction('reopen')
            ->assertRedirect(ConsultationResource::getUrl('edit', ['record' => $consultation]));
        $this->assertNull($consultation->fresh()->closed_at);
    }

    public function test_reception_only_changes_the_status_when_pressing_attend(): void
    {
        [, $appointment] = $this->appointmentToday();
        $appointment->update(['status' => AppointmentStatus::CheckedIn]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayAppointments::class)
            ->callAction(TestAction::make('start')->table($appointment))
            ->assertNoRedirect();

        $this->assertSame(AppointmentStatus::InProgress, $appointment->fresh()->status);
        $this->assertSame(0, Consultation::count());
    }

    public function test_the_odontogram_marks_surfaces_only_for_the_doctor_writing(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);

        $this->actingAs($doctor->user);

        // El navegador manda cada toque con el hallazgo, el color y la sigla elegidos.
        Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->assertSee(['Caries', 'Prótesis fija', 'Buscar otro hallazgo…'])
            ->call('mark', 36, 'O', 'caries', 'bad', 'CD')
            ->assertSee('Caries (CD · dentina)')
            ->call('mark', 14, null, 'protesis_fija', 'good', null, 16)
            ->assertSee('14 a 16')
            ->call('mark', 36, null, 'caries')
            ->assertNotified('Este hallazgo se marca en una superficie de la pieza.');

        $this->assertSame(2, $consultation->odontogram->entries()->count());

        // Un admin odontólogo lo ve, pero no lo puede cambiar: el toque solo muestra lo que tiene la pieza.
        $this->actingAs(User::factory()->admin(isDentist: true)->create());

        Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->assertDontSee('Buscar otro hallazgo…')
            ->call('mark', 11, 'V', 'caries')
            ->assertSet('selectedTooth', 11);

        $this->assertSame(2, $consultation->odontogram->entries()->count());
    }

    public function test_a_red_finding_is_marked_treated_from_the_list(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);

        $this->actingAs($doctor->user);

        $chart = Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->call('mark', 36, 'O', 'caries', 'bad', 'CD')
            ->assertSee('✓ Tratado');

        $caries = $consultation->odontogram->entries()->sole();

        $chart->call('markTreated', $caries->id, 'R')
            ->assertSee('Restauración definitiva (R · resina)')
            ->assertDontSee('✓ Tratado');

        $this->assertSame(
            [['restauracion', 36, 'O', ToothState::Good, 'R']],
            $consultation->odontogram->entries()->with('condition')->get()->map(fn ($e) => [$e->condition->code, $e->tooth, $e->surface, $e->state, $e->abbreviation])->all(),
        );
    }

    public function test_findings_written_or_dictated_are_shown_before_being_marked(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);

        $this->actingAs($doctor->user);

        Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->set('dictation', '36 MO CD, 18 ausente, 46 algo')
            ->assertSee(['36 · mesial y oclusal — Caries (CD · dentina), por tratar', '18 — Pieza dentaria ausente, tratado', '«46 algo»: No reconocí el hallazgo.'])
            ->call('addDictated', '36 MO CD, 18 ausente, 46 algo')
            ->assertNotified('Se marcaron 3 hallazgos.')
            ->assertSet('dictation', '46 algo')
            // Lo que ya estaba no se quita al dictarlo otra vez.
            ->call('addDictated', '18 ausente')
            ->assertSet('dictation', '');

        $this->assertSame([[18, null], [36, 'M'], [36, 'O']], $consultation->odontogram->entries()->orderBy('tooth')->orderBy('surface')->get()->map(fn ($e) => [$e->tooth, $e->surface])->all());
    }

    public function test_quick_writing_records_use_and_saves_new_options(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);
        $snippet = TextSnippet::where('shortcut', 'limp')->firstOrFail();

        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['Profilaxis completa', 'Atajos: /ext /limp /res'])
            ->call('callSchemaComponentMethod', 'form.procedures', 'recordSnippetUse', ['id' => $snippet->id])
            ->fillForm(['procedures' => 'Pulido y fluorización de piezas anteriores.'])
            ->callAction(TestAction::make('saveAsOption')->schemaComponent('procedures'), data: [
                'label' => 'Pulido y flúor',
                'shortcut' => 'pul',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, $snippet->usages()->where('user_id', $doctor->user->id)->value('count'));
        $mine = TextSnippet::where('shortcut', 'pul')->sole();
        $this->assertSame($doctor->user->id, $mine->user_id);
        $this->assertSame(SnippetField::Procedures, $mine->field);
        $this->assertSame('Pulido y fluorización de piezas anteriores.', $mine->body);
    }

    public function test_doctors_register_medical_background_and_alerts_show_up(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);
        $patient = $appointment->patient;

        $this->actingAs($doctor->user);

        Livewire::test(ViewClinicalRecord::class, ['record' => $patient->getRouteKey()])
            ->callAction(TestAction::make('editBackground')->schemaComponent('background', schema: 'infolist'), data: [
                'conditions' => ['diabetes'],
                'allergies' => 'Penicilina',
                'medications' => 'Ninguna',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Antecedentes guardados');

        $this->assertSame(['diabetes'], $patient->medicalBackground->conditions);
        $this->assertSame($doctor->user->id, $patient->medicalBackground->updated_by_user_id);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee('Atención: Alergia: Penicilina · Diabetes')
            ->assertDontSee('Toma: Ninguna');

        // Recepción no puede tocar los antecedentes.
        $this->actingAs(User::factory()->reception()->create());
        $this->assertFalse(auth()->user()->can('updateClinical', $patient));
    }

    public function test_a_doctor_can_attend_one_of_their_patients_without_an_appointment(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $stranger = Patient::factory()->create();

        $this->actingAs($doctor->user);

        Livewire::test(ListConsultations::class)
            ->callAction('withoutAppointment', data: ['patient_id' => $stranger->id])
            ->assertNotified('No atiendes a este paciente. Pide a recepción que le agende una cita contigo.');

        Livewire::test(ListConsultations::class)
            ->callAction('withoutAppointment', data: ['patient_id' => $appointment->patient_id])
            ->assertRedirect(ConsultationResource::getUrl('edit', ['record' => Consultation::sole()]));

        $this->assertNull(Consultation::sole()->appointment_id);
    }

    /** @return array{0: Doctor, 1: Appointment} */
    public function test_the_draft_saves_itself_only_when_something_changed(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);
        $this->actingAs($doctor->user);

        $page = Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->call('autosave')
            ->assertSet('autosavedAt', null)
            ->fillForm(['procedures' => 'Profilaxis y aplicación de flúor.'])
            ->call('autosave')
            ->assertSet('autosavedAt', '08:00')
            ->assertSee('Guardado solo a las 08:00');

        $this->assertSame('Profilaxis y aplicación de flúor.', $consultation->fresh()->procedures);

        // Un diagnóstico a medio llenar no se marca en pantalla: se guarda a mano cuando esté completo.
        $page->fillForm(['diagnoses' => [['icd10_code_id' => null, 'tooth' => '36', 'type' => 'definitive']], 'indications' => 'Cepillado tres veces al día.'])
            ->call('autosave')
            ->assertHasNoFormErrors()
            ->assertNotDispatched('form-validation-error');

        $this->assertNull($consultation->fresh()->indications);
    }

    public function test_the_allergy_stays_next_to_the_name_and_a_related_medication_is_flagged(): void
    {
        [$doctor, $appointment] = $this->appointmentToday();
        $appointment->patient->medicalBackground()->create(['allergies' => 'Penicilina (ronchas)']);
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);
        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSeeInOrder([$appointment->patient->full_name, '⚠ Alergia: Penicilina (ronchas)', 'Motivo', 'Odontograma', 'Receta', 'Terminar atención'])
            ->fillForm(['prescription_items' => [['medication' => 'Amoxicilina 500 mg', 'instructions' => '1 cada 8 horas']]])
            ->assertSee('Cuidado con Amoxicilina: el paciente tiene alergia a la penicilina.')
            ->fillForm(['prescription_items' => [['medication' => 'Ibuprofeno 400 mg', 'instructions' => null]]])
            ->assertDontSee('Cuidado con');
    }

    private function appointmentToday(?string $treatmentSlug = null): array
    {
        $treatment = $treatmentSlug ? Treatment::where('slug', $treatmentSlug)->firstOrFail() : Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()
            ->for($doctor)
            ->for($treatment)
            ->for(Patient::factory()->create(['birth_date' => now()->subYears(35)]))
            ->at('2026-11-02 10:00')
            ->confirmed()
            ->create();

        return [$doctor, $appointment];
    }
}
