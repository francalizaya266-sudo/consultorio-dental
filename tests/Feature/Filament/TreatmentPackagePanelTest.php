<?php

namespace Tests\Feature\Filament;

use App\Enums\PlanPaymentMode;
use App\Filament\Resources\Patients\Pages\ViewClinicalRecord;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Filament\Resources\TreatmentPackages\Pages\ManageTreatmentPackages;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
use App\Services\Clinical\ConsultationService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TreatmentPackagePanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $brackets;

    private Treatment $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->brackets = Treatment::factory()->create(['name' => 'Colocación de brackets', 'price_from' => 2500]);
        $this->control = Treatment::factory()->create(['name' => 'Control de ortodoncia', 'price_from' => 140]);
    }

    public function test_the_doctor_sets_prices_and_builds_a_package_with_its_monthly_amount(): void
    {
        $doctor = $this->doctorOffering($this->brackets, $this->control);
        $this->actingAs($doctor->user);

        // El doctor sube el precio del control y crea un tratamiento nuevo.
        Livewire::test(ManageTreatments::class)
            ->callAction(TestAction::make('edit')->table($this->control), data: ['price_from' => 150])
            ->assertHasNoActionErrors()
            ->callAction('create', data: ['name' => 'Retenedor', 'slug' => 'retenedor', 'duration_minutes' => 30, 'price_from' => 300])
            ->assertHasNoActionErrors();

        $this->assertSame('150.00', $this->control->fresh()->price_from);
        $this->assertSame('300.00', Treatment::where('slug', 'retenedor')->value('price_from'));

        // Junta brackets y 18 controles a S/ 4,900, con descuento por pago único o inicial y S/ 250 al mes.
        Repeater::fake();

        Livewire::test(ManageTreatmentPackages::class)
            ->mountAction('create')
            ->fillForm([
                'name' => 'Ortodoncia completa',
                'items' => [
                    ['treatment_id' => $this->brackets->id, 'quantity' => 1, 'sessions_planned' => 1],
                    ['treatment_id' => $this->control->id, 'quantity' => 18, 'sessions_planned' => 18],
                ],
                'price' => 4900,
                'allows_upfront' => true,
                'upfront_discount' => 300,
                'allows_installments' => true,
                'down_payment' => 800,
                'installments' => 6,
            ])
            ->fillForm(['monthly_amount' => 250])
            ->assertSchemaStateSet(['installments' => 17])
            ->assertMountedActionModalSee([
                'Precio normal: S/ 5,200.00',
                'Ahorra S/ 300.00',
                'Pago único de S/ 4,600.00 (con S/ 300.00 de descuento por pagar todo junto). En cuotas: inicial de S/ 800.00 y 17 cuotas mensuales: 16 de S/ 250.00 y la última de S/ 100.00.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee(['Ortodoncia completa', 'Colocación de brackets · Control de ortodoncia × 18', 'Ahorra S/ 300.00']);

        $package = TreatmentPackage::sole();
        $this->assertSame(['4900.00', 17, '250.00', $doctor->user->id], [$package->price, $package->installments, $package->monthly_amount, $package->created_by_user_id]);
        $this->assertSame([[$this->brackets->id, 1, 1], [$this->control->id, 18, 18]], $package->items->map(fn ($item) => [$item->treatment_id, $item->quantity, $item->sessions_planned])->all());
    }

    public function test_a_package_keeps_working_after_one_of_its_treatments_stops_being_offered(): void
    {
        $package = TreatmentPackage::factory()->create(['name' => 'Ortodoncia completa', 'price' => 4900]);
        $package->items()->create(['treatment_id' => $this->brackets->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 0]);
        $package->items()->create(['treatment_id' => $this->control->id, 'quantity' => 18, 'sessions_planned' => 18, 'sort_order' => 1]);
        $this->control->update(['is_active' => false]);
        $this->actingAs($this->doctorOffering($this->brackets)->user);

        // El tratamiento apagado sigue con su nombre en el paquete, y el paquete se puede guardar.
        Livewire::test(ManageTreatmentPackages::class)
            ->mountAction(TestAction::make('edit')->table($package))
            ->assertMountedActionModalSee(['Control de ortodoncia', '(inactivo)'])
            ->fillForm(['price' => 4700])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('4700.00', $package->fresh()->price);
        $this->assertSame([$this->brackets->id, $this->control->id], $package->items()->pluck('treatment_id')->all());

        // Pero a un paquete nuevo ya no se le puede agregar.
        Repeater::fake();

        Livewire::test(ManageTreatmentPackages::class)
            ->mountAction('create')
            ->fillForm(['name' => 'Controles sueltos', 'items' => [['treatment_id' => $this->control->id, 'quantity' => 6, 'sessions_planned' => 6]], 'price' => 700])
            ->callMountedAction()
            ->assertHasActionErrors();

        $this->assertSame(1, TreatmentPackage::query()->count());
    }

    public function test_a_package_that_costs_more_than_its_parts_says_so_instead_of_promising_savings(): void
    {
        $this->actingAs($this->doctorOffering($this->brackets, $this->control)->user);
        Repeater::fake();

        Livewire::test(ManageTreatmentPackages::class)
            ->mountAction('create')
            ->fillForm([
                'name' => 'Ortodoncia completa',
                'items' => [['treatment_id' => $this->brackets->id, 'quantity' => 1, 'sessions_planned' => 1]],
                'price' => 2600,
            ])
            ->assertMountedActionModalSee('Cuesta S/ 100.00 más que por separado')
            ->assertMountedActionModalDontSee('Ahorra')
            // Recién encendido "En cuotas" no hay nada mal todavía: se pide el dato que falta.
            ->fillForm(['allows_installments' => true])
            ->assertMountedActionModalSee('En cuotas: pon el número de cuotas o la cuota mensual para ver cómo queda.')
            ->fillForm(['price' => 2500])
            ->assertMountedActionModalDontSee(['Cuesta', 'Ahorra']);
    }

    public function test_switching_a_package_off_says_the_plans_that_use_it_stay(): void
    {
        $package = TreatmentPackage::factory()->create(['name' => 'Ortodoncia completa']);
        $this->actingAs($this->doctorOffering($this->brackets)->user);

        Livewire::test(ManageTreatmentPackages::class)
            ->call('updateTableColumnState', 'is_active', (string) $package->id, false)
            ->assertNotified('"Ortodoncia completa" ya no se ofrece');
        $this->assertFalse($package->fresh()->is_active);

        // La lista abre con los que se ofrecen: el apagado se busca quitando ese filtro.
        Livewire::test(ManageTreatmentPackages::class)
            ->removeTableFilter('is_active')
            ->call('updateTableColumnState', 'is_active', (string) $package->id, true)
            ->assertNotified('"Ortodoncia completa" se ofrece de nuevo');
        $this->assertTrue($package->fresh()->is_active);
    }

    public function test_a_plan_built_from_a_package_arrives_at_signing_with_the_package_terms(): void
    {
        $this->control->update(['price_from' => 150]);
        $package = TreatmentPackage::factory()->inInstallments(800, 18)->create(['name' => 'Ortodoncia completa', 'price' => 4900, 'upfront_discount' => 300]);
        $package->items()->create(['treatment_id' => $this->brackets->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 0]);
        $package->items()->create(['treatment_id' => $this->control->id, 'quantity' => 18, 'sessions_planned' => 18, 'sort_order' => 1]);
        TreatmentPackage::factory()->create(['name' => 'Ya no se ofrece', 'is_active' => false]);

        $doctor = $this->doctorOffering($this->brackets, $this->control);
        $patient = Patient::factory()->create();
        app(ConsultationService::class)->startWithoutAppointment($patient, $doctor->user);
        $this->actingAs($doctor->user);

        $component = Livewire::test(TreatmentPlansRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewClinicalRecord::class])
            ->mountAction(TestAction::make('create')->table())
            ->assertFormFieldExists('add_package')
            ->fillForm(['add_package' => $package->id])
            ->assertSchemaStateSet(['title' => 'Ortodoncia completa', 'add_package' => null])
            ->assertMountedActionModalSee(['Total: S/ 4,900.00', 'Del paquete Ortodoncia completa'])
            ->assertMountedActionModalDontSee('Ya no se ofrece')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $plan = TreatmentPlan::sole();
        $this->assertSame([[$package->id, '2500.00', '144.23'], [$package->id, '150.00', '155.77']], $plan->items->map(fn ($item) => [$item->treatment_package_id, $item->unit_price, $item->discount])->all());

        // El presupuesto muestra el ahorro y cómo se puede pagar el paquete.
        $this->get(TreatmentPlansRelationManager::printUrl($plan))
            ->assertOk()
            ->assertSee(['Paquete Ortodoncia completa', 'Ahorra S/ 300.00', 'Formas de pago del paquete', 'Pago único de S/ 4,600.00']);

        $component
            ->mountAction(TestAction::make('accept')->table($plan))
            ->assertSchemaStateSet([
                'payment_mode' => PlanPaymentMode::Installments,
                'down_payment' => 800.0,
                'installments' => 18,
                'monthly_amount' => null,
                'upfront_discount' => 300.0,
            ])
            ->assertMountedActionModalSee([
                'Paquete Ortodoncia completa: precio normal S/ 5,200.00, en el paquete S/ 4,900.00 (ahorra S/ 300.00).',
                'Inicial de S/ 800.00 el 02/11/2026 y 17 cuotas de S/ 228.00 y la última de S/ 224.00, del 02/12/2026 al 02/05/2028.',
            ]);
    }
}
