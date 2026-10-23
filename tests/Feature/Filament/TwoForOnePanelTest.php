<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Patients\Pages\ViewClinicalRecord;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Filament\Resources\TreatmentPackages\Pages\ManageTreatmentPackages;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
use App\Services\Clinical\ConsultationService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TwoForOnePanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Doctor $doctor;

    private Patient $payer;

    private Patient $daughter;

    private TreatmentPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $whitening = Treatment::factory()->create(['name' => 'Blanqueamiento', 'price_from' => 480]);
        $this->doctor = $this->doctorOffering($cleaning, $whitening);
        $this->payer = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->daughter = Patient::factory()->dependentOf($this->payer->account)->create(['first_name' => 'Lucía', 'last_name' => 'Quispe']);

        $this->package = TreatmentPackage::factory()->create(['name' => 'Sonrisa en familia', 'price' => 500, 'for_two' => true, 'description' => null]);
        $this->package->items()->create(['treatment_id' => $cleaning->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 0]);
        $this->package->items()->create(['treatment_id' => $whitening->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 1]);
    }

    public function test_the_doctor_picks_who_else_comes_with_the_two_for_one_and_both_plans_say_so(): void
    {
        app(ConsultationService::class)->startWithoutAppointment($this->payer, $this->doctor->user);
        $this->actingAs($this->doctor->user);

        Livewire::test(ManageTreatmentPackages::class)
            ->assertTableColumnHasDescription('price', 'Ahorra S/ 100.00 · 2x1: atiende a 2 personas', $this->package);

        $component = Livewire::test(TreatmentPlansRelationManager::class, ['ownerRecord' => $this->payer, 'pageClass' => ViewClinicalRecord::class])
            ->mountAction(TestAction::make('create')->table())
            ->assertFormFieldHidden('companion_patient_id')
            ->fillForm(['add_package' => $this->package->id])
            ->assertFormFieldVisible('companion_patient_id')
            ->fillForm(['companion_patient_id' => $this->daughter->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('2x1: se armó el plan de Lucía Quispe');

        $plan = TreatmentPlan::where('patient_id', $this->payer->id)->sole();
        $companionPlan = TreatmentPlan::where('patient_id', $this->daughter->id)->sole();
        $this->assertSame([$plan->id, 0.0], [$companionPlan->included_in_plan_id, $companionPlan->total()]);

        $component
            ->assertTableColumnHasDescription('payment', '2x1 con Lucía Quispe', $plan)
            ->mountAction(TestAction::make('accept')->table($plan))
            ->assertMountedActionModalSee('2x1: con este plan se atiende también a Lucía Quispe, sin costo.');

        $this->get(TreatmentPlansRelationManager::printUrl($plan))
            ->assertOk()
            ->assertSee('con este plan se atiende también a Lucía Quispe, sin costo.');

        // En la historia de la hija: incluido en el 2x1 y sin firma propia hasta que firme la mamá.
        app(ConsultationService::class)->startWithoutAppointment($this->daughter, $this->doctor->user);

        Livewire::test(TreatmentPlansRelationManager::class, ['ownerRecord' => $this->daughter, 'pageClass' => ViewClinicalRecord::class])
            ->assertTableColumnStateSet('payment', 'Incluido en el 2x1', $companionPlan)
            ->assertTableColumnHasDescription('payment', 'Lo paga Rosa Quispe · se acepta cuando firme', $companionPlan)
            ->assertActionHidden(TestAction::make('accept')->table($companionPlan))
            ->mountAction(TestAction::make('edit')->table($companionPlan))
            ->assertMountedActionModalSee('Va incluido en el 2x1 de Rosa Quispe')
            ->assertFormFieldHidden('companion_patient_id');

        $this->get(TreatmentPlansRelationManager::printUrl($companionPlan))
            ->assertOk()
            ->assertSee(['Incluido en el 2x1 de Rosa Quispe:', 'S/ 0.00'])
            ->assertDontSee('Formas de pago del paquete');
    }

    public function test_a_campaign_with_a_package_arrives_chosen_in_the_consultation(): void
    {
        $campaign = Campaign::query()->create([
            'name' => 'Sonrisa de verano',
            'slug' => 'sonrisa-verano',
            'treatment_package_id' => $this->package->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        $appointment = Appointment::factory()->create(['patient_id' => $this->payer->id, 'doctor_id' => $this->doctor->id, 'campaign_id' => $campaign->id, 'starts_at' => now(), 'ends_at' => now()->addMinutes(30)]);
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $this->doctor->user);
        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->mountAction(TestAction::make('addPackage')->schemaComponent('plan'))
            ->assertSchemaStateSet(['package_id' => $this->package->id])
            ->assertMountedActionModalSee([
                'Viene por la campaña Sonrisa de verano.',
                'Incluye: Limpieza dental · Blanqueamiento.',
                'Precio normal S/ 600.00, en el paquete S/ 500.00 (ahorra S/ 100.00).',
            ])
            ->assertFormFieldVisible('companion_patient_id')
            ->fillForm(['companion_patient_id' => $this->daughter->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Se agregó Sonrisa en familia al plan (S/ 500.00).');

        $plan = TreatmentPlan::where('patient_id', $this->payer->id)->sole();
        $this->assertSame(['Sonrisa en familia', 500.0], [$plan->title, $plan->total()]);
        $this->assertSame($plan->id, TreatmentPlan::where('patient_id', $this->daughter->id)->sole()->included_in_plan_id);
    }
}
