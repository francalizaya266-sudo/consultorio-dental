<?php

namespace Tests\Feature\Filament;

use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\ToothState;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Patients\Pages\ViewClinicalRecord;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Livewire\OdontogramChart;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Icd10Code;
use App\Models\OdontogramCondition;
use App\Models\Patient;
use App\Models\TextSnippet;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\OdontogramService;
use App\Services\Clinical\OdontogramSuggestions;
use App\Support\Phone;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TreatmentPlanPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();

        Treatment::where('slug', 'curacion-con-resina')->update(['price_from' => 150]);
    }

    public function test_a_new_plan_comes_filled_from_the_odontogram_and_is_accepted_printed_and_sent(): void
    {
        [$doctor, $patient] = $this->patientWithCariesIn36();
        $this->actingAs($doctor->user);

        $component = Livewire::test(TreatmentPlansRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewClinicalRecord::class])
            ->callAction(TestAction::make('create')->table(), data: ['title' => 'Plan inicial'])
            ->assertHasNoActionErrors();

        $plan = TreatmentPlan::sole();
        $this->assertSame([TreatmentPlanStatus::Draft, $doctor->id], [$plan->status, $plan->doctor_id]);
        $this->assertSame([['Curación con resina', '36', 'O', '150.00']], $plan->items->map(fn ($item) => [$item->description, $item->tooth, $item->surfaces, $item->unit_price])->all());

        // Imprimirlo es entregarlo: pasa a "presentado".
        $this->get(TreatmentPlansRelationManager::printUrl($plan))
            ->assertOk()
            ->assertSee(['Plan de tratamiento y presupuesto', 'Curación con resina', 'S/ 150.00']);
        $this->assertSame(TreatmentPlanStatus::Proposed, $plan->fresh()->status);

        $component
            ->callAction(TestAction::make('accept')->table($plan))
            ->mountAction(TestAction::make('sendWhatsApp')->table($plan))
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('Total: S/ 150.00', $state['body']) ?? [])
            ->callMountedAction();

        $this->assertSame(TreatmentPlanStatus::Accepted, $plan->fresh()->status);
        $message = WhatsappMessage::sole();
        $this->assertSame([MessageTemplateKey::TreatmentPlan, MessageStatus::Sent, $patient->id], [$message->template_key, $message->status, $message->patient_id]);

        // Recepción no ve presupuestos clínicos.
        $this->actingAs(User::factory()->reception()->create());
        $this->get(TreatmentPlansRelationManager::printUrl($plan))->assertForbidden();
    }

    public function test_reception_books_a_plan_session_and_attending_it_marks_it(): void
    {
        $resin = Treatment::where('slug', 'curacion-con-resina')->firstOrFail();
        $doctor = $this->doctorOffering($resin);
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Proposed]);
        $item = $plan->items()->create(['treatment_id' => $resin->id, 'description' => 'Curación con resina', 'tooth' => '36', 'unit_price' => 150]);

        $this->actingAs(User::factory()->reception()->create());

        // Al elegir la sesión del plan, el doctor y el tratamiento se completan solos.
        Livewire::withQueryParams(['patient' => $patient->id])
            ->test(CreateAppointment::class)
            ->fillForm(['treatment_plan_item_id' => $item->id])
            ->assertSchemaStateSet(['doctor_id' => $doctor->id, 'treatment_id' => $resin->id]);

        Livewire::withQueryParams(['patient' => $patient->id, 'doctor' => $doctor->id, 'treatment' => $resin->id, 'plan_item' => $item->id])
            ->test(CreateAppointment::class)
            ->assertSchemaStateSet(['patient_id' => $patient->id, 'treatment_plan_item_id' => $item->id, 'treatment_id' => $resin->id])
            ->assertSee('Curación con resina')
            ->assertDontSee('pieza 36')
            ->fillForm(['date' => '2026-11-03', 'slot' => '10:00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $appointment = Appointment::sole();
        $this->assertTrue($appointment->planItem->is($item));
        $this->assertSame(TreatmentPlanStatus::Accepted, $plan->fresh()->status);

        $this->travelTo('2026-11-03 10:00');
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);
        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSchemaStateSet(['planItems' => [(string) $item->id]])
            ->assertSee('Curación con resina · pieza 36')
            ->callAction('close');

        $this->assertSame(1, $item->fresh()->sessions_done);
        $this->assertSame(TreatmentPlanStatus::Completed, $plan->fresh()->status);
    }

    public function test_the_doctor_adds_the_odontogram_suggestions_to_the_diagnosis_and_the_plan(): void
    {
        [$doctor, $patient] = $this->patientWithCariesIn36();
        $consultation = $patient->consultations()->sole();
        $this->actingAs($doctor->user);

        $chart = Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->assertSee(['Para el diagnóstico y el plan', 'Curación con resina', 'K02.1 Caries de la dentina', 'S/ 150.00']);

        $key = app(OdontogramSuggestions::class)->rows($consultation->odontogram, $consultation)[0]['key'];
        $chart->call('applySuggestions', [$key])
            ->assertDispatched('odontogram-suggestions-applied', diagnoses: [['icd10_code_id' => Icd10Code::where('code', 'K02.1')->value('id'), 'tooth' => '36']], planned: 1, plannedTotal: 'S/ 150.00')
            ->assertSee('Ya agregado');

        $this->assertSame([['Curación con resina', '36', 'O']], TreatmentPlan::sole()->items->map(fn ($item) => [$item->description, $item->tooth, $item->surfaces])->all());

        // La atención guarda el diagnóstico y lo suma a su lista; guardar después no lo duplica.
        $page = Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->dispatch('odontogram-suggestions-applied', diagnoses: [['icd10_code_id' => Icd10Code::where('code', 'K02.1')->value('id'), 'tooth' => '36']], planned: 1, plannedTotal: 'S/ 150.00')
            ->assertNotified('Se agregaron 1 procedimiento al plan (S/ 150.00) y 1 diagnóstico.')
            ->assertSee('Curación con resina · pieza 36 (O)')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['K02.1'], $consultation->diagnoses()->with('icd10Code')->get()->pluck('icd10Code.code')->all());

        $page->dispatch('odontogram-suggestions-applied', diagnoses: [['icd10_code_id' => Icd10Code::where('code', 'K02.1')->value('id'), 'tooth' => '36']])
            ->assertNotified('Eso ya estaba agregado.');
        $this->assertSame(1, $consultation->diagnoses()->count());
    }

    public function test_marking_a_planned_finding_treated_marks_it_worked_today(): void
    {
        [$doctor, $patient] = $this->patientWithCariesIn36();
        $consultation = $patient->consultations()->sole();
        $this->actingAs($doctor->user);
        app(OdontogramSuggestions::class)->apply($consultation, $doctor->user, array_column(app(OdontogramSuggestions::class)->rows($consultation->odontogram), 'key'));
        $item = TreatmentPlan::sole()->items()->sole();

        Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->call('markTreated', $consultation->odontogram->entries()->sole()->id, 'R')
            ->assertDispatched('odontogram-plan-items-worked', items: [$item->id]);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSchemaStateSet(['planItems' => []])
            ->dispatch('odontogram-plan-items-worked', items: [$item->id])
            ->assertSchemaStateSet(['planItems' => [(string) $item->id]])
            ->assertNotified('Marcado como trabajado hoy: Curación con resina · pieza 36 (O).')
            ->callAction('close');

        $this->assertSame(1, $item->fresh()->sessions_done);
    }

    public function test_the_doctor_writes_a_prescription_prints_it_and_sends_it(): void
    {
        [$doctor, $patient] = $this->patientWithCariesIn36();
        $consultation = $patient->consultations()->sole();
        $ibuprofen = TextSnippet::where('shortcut', 'ibu')->firstOrFail();
        $this->actingAs($doctor->user);

        $component = Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->fillForm([
                'indications' => 'Dieta blanda por 2 días.',
                'prescription_items' => [['medication' => 'Amoxicilina 500 mg', 'instructions' => '1 cápsula cada 8 horas por 7 días']],
            ])
            ->callAction(TestAction::make('addMedication'.$ibuprofen->id)->schemaComponent('prescription'))
            ->call('save')
            ->assertHasNoFormErrors();

        $prescription = $consultation->fresh()->prescription;
        $this->assertSame(['Amoxicilina 500 mg', 'Ibuprofeno 400 mg'], $prescription->items->pluck('medication')->all());
        $this->assertSame('Dieta blanda por 2 días.', $prescription->indications);
        $this->assertSame(1, $ibuprofen->usages()->where('user_id', $doctor->user->id)->value('count'));

        $component
            ->mountAction(TestAction::make('sendPrescription')->schemaComponent('prescription'))
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString("• Amoxicilina 500 mg: 1 cápsula cada 8 horas por 7 días\n• Ibuprofeno 400 mg", $state['body']) ?? [])
            ->callMountedAction();

        $this->assertSame([MessageTemplateKey::Prescription, MessageStatus::Sent], [WhatsappMessage::sole()->template_key, WhatsappMessage::sole()->status]);

        $consultation->diagnoses()->create(['icd10_code_id' => Icd10Code::where('code', 'K02.1')->value('id'), 'tooth' => '36']);

        // Con los datos del recetario de la clínica: teléfono del paciente, diagnóstico y lema al pie.
        $this->get(ConsultationResource::prescriptionPrintUrl($prescription))
            ->assertOk()
            ->assertSee(['Rp.', 'Amoxicilina 500 mg', 'Dieta blanda por 2 días.', $patient->full_name])
            ->assertSee(['Clínica Vega Implant', 'Jr. Apurímac 648, Juliaca', 'WhatsApp 918 874 568', 'C.O.P. '.$doctor->cop_number])
            ->assertSee(['Teléfono', Phone::display($patient->account->phone), 'K02.1 Caries de la dentina (pieza 36)', 'Tu sonrisa, nuestra pasión']);

        $this->actingAs(User::factory()->reception()->create());
        $this->get(ConsultationResource::prescriptionPrintUrl($prescription))->assertForbidden();
    }

    /** @return array{0: Doctor, 1: Patient} Paciente con una atención abierta y caries en la oclusal de 36. */
    private function patientWithCariesIn36(): array
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $patient = Patient::factory()->create();
        $consultation = app(ConsultationService::class)->startWithoutAppointment($patient, $doctor->user);
        app(OdontogramService::class)->toggle($consultation->odontogram, OdontogramCondition::where('code', 'caries')->firstOrFail(), ToothState::Bad, 36, 'O');

        return [$doctor, $patient];
    }
}
