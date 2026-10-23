<?php

namespace Tests\Feature\Services\Clinical;

use App\Enums\PlanItemStatus;
use App\Enums\ToothState;
use App\Enums\TreatmentPlanStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Icd10Code;
use App\Models\OdontogramCondition;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\OdontogramService;
use App\Services\Clinical\OdontogramSuggestions;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\WhatsApp\WhatsAppOutbox;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TreatmentPlanAndPrescriptionTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();

        Treatment::where('slug', 'curacion-con-resina')->update(['price_from' => 150]);
        Treatment::where('slug', 'extraccion-simple')->update(['price_from' => 120]);
    }

    public function test_the_odontogram_suggests_diagnoses_and_procedures_by_abbreviation_with_catalog_prices(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $consultation = app(ConsultationService::class)->startWithoutAppointment(Patient::factory()->create(), $doctor->user);
        $odontogram = $consultation->odontogram;

        $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 36, 'O', abbreviation: 'CD');
        $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 36, 'M', abbreviation: 'CD');
        $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 24, 'O', abbreviation: 'MB');
        $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 46, 'O', abbreviation: 'CDP');
        $this->odontograms()->toggle($odontogram, $this->condition('extraccion_indicada'), ToothState::Bad, 48);
        $this->odontograms()->toggle($odontogram, $this->condition('ausente'), ToothState::Good, 18);
        $this->odontograms()->toggle($odontogram, $this->condition('restauracion'), ToothState::Good, 26, 'O');

        $rows = collect($this->suggestions()->rows($odontogram, $consultation));

        // Mancha blanca solo se diagnostica; con compromiso pulpar se propone también la endodoncia; la pieza ausente, un implante opcional.
        $this->assertSame(
            [
                ['24', 'O', null, ['K02.0'], true],
                ['36', 'MO', 'Curación con resina', ['K02.1'], true],
                ['46', 'O', 'Curación con resina', ['K02.1'], true],
                ['46', 'O', 'Endodoncia', ['K04.0'], true],
                ['48', null, 'Extracción simple', [], true],
                ['18', null, 'Implante', ['K08.1'], false],
            ],
            $rows->map(fn (array $row) => [$row['tooth'], $row['surfaces'], $row['treatment']?->name, $row['diagnoses']->pluck('code')->all(), $row['suggested']])->all(),
        );

        $result = $this->suggestions()->apply($consultation, $doctor->user, $rows->where('suggested', true)->pluck('key')->all());

        $this->assertSame(4, $result['added']);
        $this->assertSame(TreatmentPlanStatus::Draft, $result['plan']->status);
        $this->assertSame(
            [['Curación con resina', '36', 'MO', '150.00'], ['Curación con resina', '46', 'O', '150.00'], ['Endodoncia', '46', 'O', '0.00'], ['Extracción simple', '48', null, '120.00']],
            $result['plan']->items->map(fn ($item) => [$item->description, $item->tooth, $item->surfaces, $item->unit_price])->all(),
        );
        $this->assertSame(420.0, $result['total']);
        $this->assertSame([['24', 'K02.0'], ['36', 'K02.1'], ['46', 'K02.1'], ['46', 'K04.0']], collect($result['diagnoses'])->map(fn ($d) => [$d['tooth'], Icd10Code::find($d['icd10_code_id'])->code])->all());

        // Con los diagnósticos guardados por la atención, todo queda hecho y nada se vuelve a agregar; el implante sigue esperando al doctor.
        foreach ($result['diagnoses'] as $diagnosis) {
            $consultation->diagnoses()->create([...$diagnosis, 'type' => 'definitive']);
        }

        $again = collect($this->suggestions()->rows($odontogram, $consultation));
        $this->assertSame([], $again->where('suggested', true)->pluck('tooth')->all());
        $this->assertSame(['24', '36', '46', '46', '48'], $again->where('done', true)->pluck('tooth')->all());
        $this->assertSame(['added' => 0, 'diagnoses' => []], collect($this->suggestions()->apply($consultation, $doctor->user, $again->where('optional', false)->pluck('key')->all()))->only(['added', 'diagnoses'])->all());
    }

    public function test_marking_treated_finishes_the_plan_procedure_once_nothing_red_is_left_on_the_tooth(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $consultation = app(ConsultationService::class)->startWithoutAppointment(Patient::factory()->create(), $doctor->user);
        $odontogram = $consultation->odontogram;
        $occlusal = $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 36, 'O');
        $mesial = $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 36, 'M');
        $this->suggestions()->apply($consultation, $doctor->user, array_column($this->suggestions()->rows($odontogram, $consultation), 'key'));
        $item = TreatmentPlan::sole()->items()->sole();

        $this->odontograms()->markTreated($occlusal);
        $this->assertSame([], $this->suggestions()->finishedItems($consultation->fresh(), 36, 'caries', null)->modelKeys(), 'Falta la mesial.');

        $this->odontograms()->markTreated($mesial);
        $this->assertSame([$item->id], $this->suggestions()->finishedItems($consultation->fresh(), 36, 'caries', null)->modelKeys());
    }

    public function test_the_plan_advances_when_its_sessions_are_attended_and_goes_back_if_reopened(): void
    {
        $endodontics = Treatment::where('slug', 'endodoncia')->firstOrFail();
        $doctor = $this->doctorOffering($endodontics);
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Proposed]);
        $endo = $plan->items()->create(['treatment_id' => $endodontics->id, 'description' => 'Endodoncia', 'tooth' => '36', 'unit_price' => 600, 'sessions_planned' => 2]);
        $resin = $plan->items()->create(['description' => 'Curación con resina', 'tooth' => '36', 'unit_price' => 150]);

        // La cita es una sesión del plan: la atención ya llega con ese procedimiento marcado.
        $appointment = Appointment::factory()->for($doctor)->for($endodontics)->for($patient)->at('2026-11-02 10:00')->confirmed()->create(['treatment_plan_item_id' => $endo->id]);
        $first = $this->consultations()->startFromAppointment($appointment, $doctor->user);
        $this->assertTrue($first->planItems()->whereKey($endo->id)->exists());

        $this->consultations()->close($first, $doctor->user);

        $this->assertSame([1, PlanItemStatus::InProgress], [$endo->fresh()->sessions_done, $endo->fresh()->status]);
        $this->assertSame(TreatmentPlanStatus::InProgress, $plan->fresh()->status);
        $this->assertNotNull($plan->fresh()->accepted_at);

        $second = $this->consultations()->startWithoutAppointment($patient, $doctor->user);
        $second->planItems()->attach([$endo->id, $resin->id]);
        $this->consultations()->close($second, $doctor->user);

        $this->assertSame(PlanItemStatus::Done, $endo->fresh()->status);
        $this->assertSame(PlanItemStatus::Done, $resin->fresh()->status);
        $this->assertSame(TreatmentPlanStatus::Completed, $plan->fresh()->status);

        // Reabrir para corregir deja el avance como estaba antes.
        $this->consultations()->reopen($second->fresh(), $doctor->user);

        $this->assertSame([1, PlanItemStatus::InProgress], [$endo->fresh()->sessions_done, $endo->fresh()->status]);
        $this->assertSame(PlanItemStatus::Pending, $resin->fresh()->status);
        $this->assertSame(TreatmentPlanStatus::InProgress, $plan->fresh()->status);
        $this->assertNull($plan->fresh()->completed_at);
    }

    public function test_the_prescription_starts_from_the_template_and_is_kept_without_losing_history(): void
    {
        $extraction = Treatment::where('slug', 'extraccion-simple')->firstOrFail();
        $doctor = $this->doctorOffering($extraction);
        $appointment = Appointment::factory()->for($doctor)->for($extraction)->at('2026-11-02 10:00')->confirmed()->create();

        $consultation = $this->consultations()->startFromAppointment($appointment, $doctor->user);
        $prescription = $consultation->prescription;

        $this->assertSame(
            [['medication' => 'Ibuprofeno 400 mg', 'instructions' => '1 tableta cada 8 horas por 3 días']],
            $this->prescriptions()->itemsOf($consultation),
        );
        $this->assertStringContainsString('No enjuagarse', $prescription->indications);

        $original = $prescription->items()->sole();
        $this->prescriptions()->syncForConsultation($consultation, [
            'a' => ['medication' => 'Ibuprofeno 400 mg', 'instructions' => '1 tableta cada 8 horas por 2 días'],
            'b' => ['medication' => 'Amoxicilina 500 mg', 'instructions' => '1 cápsula cada 8 horas por 7 días'],
            'c' => ['medication' => '', 'instructions' => 'renglón vacío'],
        ]);

        $this->assertSame(2, $prescription->items()->count());
        $this->assertSame('1 tableta cada 8 horas por 2 días', $original->fresh()->instructions);

        // Sin medicamentos, la receta se anula pero queda en la auditoría.
        $this->assertNull($this->prescriptions()->syncForConsultation($consultation->fresh(), []));
        $this->assertNull($consultation->fresh()->prescription);
        $this->assertSoftDeleted($prescription);
        $this->assertTrue(AuditLog::where('auditable_type', $prescription->getMorphClass())->where('auditable_id', $prescription->id)->where('event', 'deleted')->exists());
    }

    public function test_the_prescription_and_the_plan_are_written_for_whatsapp(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'Rosa']);
        $doctor = Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        $consultation = Consultation::factory()->for($patient)->for($doctor)->create(['indications' => 'Dieta blanda.']);
        $prescription = $this->prescriptions()->syncForConsultation($consultation, [['medication' => 'Paracetamol 500 mg', 'instructions' => '1 tableta cada 8 horas si hay dolor']]);

        $body = app(WhatsAppOutbox::class)->render($this->prescriptions()->whatsappDraft($prescription));

        $this->assertSame("Hola Rosa, te enviamos tu receta e indicaciones de Dra. Ana Torres:\n\n• Paracetamol 500 mg: 1 tableta cada 8 horas si hay dolor\n\nIndicaciones:\nDieta blanda.", $body);

        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Draft]);
        $plan->items()->create(['description' => 'Curación con resina', 'tooth' => '36', 'surfaces' => 'MO', 'unit_price' => 150, 'quantity' => 2, 'discount' => 50]);
        $plan->items()->create(['description' => 'Blanqueamiento', 'unit_price' => 450, 'status' => PlanItemStatus::Cancelled]);

        $body = app(WhatsAppOutbox::class)->render($this->plans()->whatsappDraft($plan));

        $this->assertStringContainsString('• Curación con resina · pieza 36 (MO) × 2: S/ 250.00', $body);
        $this->assertStringContainsString('Total: S/ 250.00', $body);
        $this->assertStringNotContainsString('Blanqueamiento', $body);

        // Sin celular no hay a dónde enviarlo.
        $patient->update(['account_id' => null]);
        $this->assertNull($this->prescriptions()->whatsappDraft(Prescription::find($prescription->id)));
    }

    private function condition(string $code): OdontogramCondition
    {
        return OdontogramCondition::where('code', $code)->firstOrFail();
    }

    private function consultations(): ConsultationService
    {
        return app(ConsultationService::class);
    }

    private function odontograms(): OdontogramService
    {
        return app(OdontogramService::class);
    }

    private function suggestions(): OdontogramSuggestions
    {
        return app(OdontogramSuggestions::class);
    }

    private function plans(): TreatmentPlanService
    {
        return app(TreatmentPlanService::class);
    }

    private function prescriptions(): PrescriptionService
    {
        return app(PrescriptionService::class);
    }
}
