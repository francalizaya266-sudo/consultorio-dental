<?php

namespace Tests\Feature\Filament;

use App\Enums\PlanItemStatus;
use App\Enums\ToothState;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\OdontogramCondition;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\OdontogramService;
use App\Services\Clinical\OdontogramSuggestions;
use App\Services\Clinical\TreatmentPlanService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Lo que se presupuesta en la evaluación no se queda ahí: se ve, se acepta y guía las citas siguientes. */
class PlanFromTheVisitTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $resin;

    private Doctor $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();

        Treatment::where('slug', 'curacion-con-resina')->update(['price_from' => 150]);
        Treatment::where('slug', 'limpieza-dental')->update(['price_from' => 80]);
        $this->resin = Treatment::where('slug', 'curacion-con-resina')->firstOrFail();
        $this->doctor = $this->doctorOffering($this->resin);
        $this->patient = Patient::factory()->create();
    }

    public function test_the_budget_from_the_evaluation_shows_when_it_ends_and_the_patient_accepts_it_right_there(): void
    {
        $evaluation = $this->visitWithCariesIn(36);
        $suggestions = app(OdontogramSuggestions::class);
        $suggestions->apply($evaluation, $this->doctor->user, [$suggestions->rows($evaluation->odontogram, $evaluation)[0]['key']]);
        app(ConsultationService::class)->close($evaluation, $this->doctor->user);
        $plan = TreatmentPlan::sole();
        $this->actingAs($this->doctor->user);

        // Sale arriba, para que lo acepte o se lo lleve; la próxima cita ofrece su primera sesión.
        Livewire::test(ViewConsultation::class, ['record' => $evaluation->getRouteKey()])
            ->assertSeeInOrder(['Plan de tratamiento', 'Presupuesto por aceptar: S/ 150.00', 'Curación con resina · pieza 36 (O) · vale hasta el', 'Imprimir', 'Acepta y firma'])
            ->assertSeeInOrder(['Próxima cita', 'Primera sesión del presupuesto: Curación con resina · pieza 36 (O)', 'Sugerida el lunes 9 de noviembre'])
            ->assertSeeHtml(e(AppointmentResource::nextVisitUrl($this->patient, $this->doctor->id, CarbonImmutable::parse('2026-11-09'), $plan->items->sole())))
            ->assertSeeInOrder(['Presupuesto en borrador', 'Curación con resina · pieza 36 (O)', 'S/ 150.00', 'Por hacer', 'Total', '0 de 1 hechos'])
            ->mountAction('acceptPlan')
            ->assertMountedActionModalSee(['Aceptar el plan', 'Total: S/ 150.00'])
            ->fillForm(['signature' => $this->signature()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Plan aceptado y firmado');

        $this->assertSame(TreatmentPlanStatus::Accepted, $plan->fresh()->status);

        Livewire::test(ViewConsultation::class, ['record' => $evaluation->getRouteKey()])
            ->assertSee(['Aceptó su plan: S/ 150.00', 'Próxima: Curación con resina · pieza 36 (O)', 'Plan aceptado'])
            ->assertDontSee(['Acepta y firma', 'Presupuesto por aceptar']);
    }

    public function test_what_a_later_visit_needs_joins_the_plan_in_course_and_the_patient_signs_only_that(): void
    {
        $plan = TreatmentPlan::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'title' => 'Plan de tratamiento', 'status' => TreatmentPlanStatus::InProgress, 'accepted_at' => now()->subWeek()]);
        $plan->items()->create(['treatment_id' => $this->resin->id, 'description' => 'Curación con resina', 'tooth' => '36', 'surfaces' => 'O', 'unit_price' => 150, 'accepted_at' => now()->subWeek(), 'status' => PlanItemStatus::Done, 'sessions_done' => 1]);
        $visit = $this->visitWithCariesIn(46);
        $this->actingAs($this->doctor->user);

        // Lo que necesita y no se hace hoy se suma a su plan, con el precio del catálogo.
        Livewire::test(EditConsultation::class, ['record' => $visit->getRouteKey()])
            ->mountAction(TestAction::make('addToPlan')->schemaComponent('plan'))
            ->assertMountedActionModalSee('Se suma a su plan en curso. Lo agregado lo firma aparte y se paga por sesión.')
            ->fillForm(['treatment_id' => Treatment::where('slug', 'limpieza-dental')->value('id')])
            ->assertSchemaStateSet(['unit_price' => 80.0, 'sessions_planned' => 1], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Se agregó Limpieza dental a su plan (S/ 80.00).');

        // Lo nuevo del odontograma también va a su plan, no a un presupuesto aparte.
        $suggestions = app(OdontogramSuggestions::class);
        $this->assertTrue($suggestions->apply($visit, $this->doctor->user, [$suggestions->rows($visit->odontogram, $visit)[0]['key']])['plan']->is($plan));
        $this->assertSame(1, TreatmentPlan::count());
        $this->assertSame(['Limpieza dental', 'Curación con resina'], $plan->items()->whereNull('accepted_at')->orderBy('sort_order')->pluck('description')->all());

        app(ConsultationService::class)->close($visit, $this->doctor->user);

        Livewire::test(ViewConsultation::class, ['record' => $visit->getRouteKey()])
            ->assertSeeInOrder(['Lo agregado a su plan, por aceptar: S/ 230.00', 'Limpieza dental · Curación con resina · pieza 46 (O)', 'Firma lo agregado'])
            ->assertSeeInOrder(['En tratamiento', 'Curación con resina · pieza 36 (O)', 'Hecho', 'Limpieza dental', 'Por hacer · falta que lo acepte', 'Total', 'S/ 380.00', '1 de 3 hechos'])
            ->mountAction('acceptPlan')
            ->assertMountedActionModalSee(['Aceptar lo agregado al plan', 'Total: S/ 230.00'])
            ->fillForm(['signature' => $this->signature()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Lo agregado quedó aceptado');

        $this->assertSame(0, $plan->items()->whereNull('accepted_at')->count());
    }

    public function test_a_package_still_goes_in_its_own_budget(): void
    {
        $plan = TreatmentPlan::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()->subWeek()]);
        $visit = $this->visitWithCariesIn(46);
        $cleaning = Treatment::where('slug', 'limpieza-dental')->firstOrFail();
        $plans = app(TreatmentPlanService::class);

        [$draft] = $plans->addToDraft($visit, $this->doctor->user, [$plans->itemFor($cleaning)]);

        $this->assertFalse($draft->is($plan));
        $this->assertSame(TreatmentPlanStatus::Draft, $draft->status);
    }

    private function visitWithCariesIn(int $tooth): Consultation
    {
        $visit = app(ConsultationService::class)->startWithoutAppointment($this->patient, $this->doctor->user);
        app(OdontogramService::class)->toggle($visit->odontogram, OdontogramCondition::where('code', 'caries')->firstOrFail(), ToothState::Bad, $tooth, 'O');

        return $visit;
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(300, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 20, 80, 280, 40, imagecolorallocate($image, 15, 23, 42));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
