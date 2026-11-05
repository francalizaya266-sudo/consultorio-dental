<?php

namespace Tests\Feature\Filament;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\StockCount;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\PurchaseOrders\Pages\ManagePurchaseOrders;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Supplies\Pages\ManageSupplies;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Filament\Widgets\FollowUpsToday;
use App\Models\Appointment;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Supply;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Clinical\ConsultationService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StarterCatalog;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class InventoryPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
    }

    public function test_reception_runs_the_inventory_the_doctor_sees_the_stock_and_only_the_admin_sees_costs(): void
    {
        $supply = Supply::factory()->create(['name' => 'Guantes de nitrilo', 'unit' => 'par', 'unit_cost' => 0.85]);
        $inventoryPages = [SupplyResource::getUrl('index'), StockMovementResource::getUrl('index'), PurchaseOrderResource::getUrl('index'), SupplierResource::getUrl('index'), StockCount::getUrl()];

        $this->actingAs(User::factory()->admin()->create());
        foreach ($inventoryPages as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(SupplyResource::getUrl('index'))->assertSee(['Guantes de nitrilo', 'S/ 0.85']);

        $this->actingAs(User::factory()->reception()->create());
        foreach ($inventoryPages as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(SupplyResource::getUrl('index'))->assertSee('Guantes de nitrilo')->assertDontSee('S/ 0.85');

        // El doctor ve lo que hay y por dónde se fue; las compras y el conteo son de recepción.
        $this->actingAs($this->doctorOffering(Treatment::factory()->create())->user);
        $this->get(SupplyResource::getUrl('index'))->assertOk()->assertSee('Guantes de nitrilo')->assertDontSee(['S/ 0.85', 'Entrada']);
        $this->get(SupplyResource::getUrl('view', ['record' => $supply]))->assertOk();
        $this->get(StockMovementResource::getUrl('index'))->assertOk();
        $this->get(PurchaseOrderResource::getUrl('index'))->assertForbidden();
        $this->get(SupplierResource::getUrl('index'))->assertForbidden();
        $this->get(StockCount::getUrl())->assertForbidden();
    }

    public function test_the_clinic_starts_with_the_suggested_list_and_then_counts_what_it_has(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageSupplies::class)
            ->assertActionVisible('starter')
            ->callAction('starter')
            ->assertNotified();

        $this->assertSame(count(StarterCatalog::SUPPLIES), Supply::count());
        $this->assertSame(
            ['Baberos descartables', 'Copas de profilaxis', 'Eyectores de saliva', 'Guantes de nitrilo', 'Pasta profiláctica'],
            Treatment::where('slug', 'limpieza-dental')->sole()->supplies()->orderBy('name')->pluck('name')->all(),
        );

        $gloves = Supply::where('name', 'Guantes de nitrilo')->sole();
        $anesthesia = Supply::where('name', 'Lidocaína 2 % con epinefrina')->sole();

        Livewire::test(StockCount::class)
            ->assertSchemaStateSet(['reason' => 'Conteo de noviembre 2026'])
            ->fillForm(['counted' => [$gloves->id => 40, $anesthesia->id => 25]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Conteo guardado: 2 materiales ajustados.');

        $this->assertSame(40.0, (float) $gloves->fresh()->stock);
        $this->assertSame(25.0, (float) $anesthesia->fresh()->stock);
        $this->assertSame(['Conteo de noviembre 2026'], StockMovement::where('type', StockMovementType::Adjustment)->distinct()->pluck('reason')->all());

        // Con lo que ya hay, la lista sugerida desaparece.
        Livewire::test(ManageSupplies::class)->assertActionHidden('starter');
    }

    public function test_reception_receives_material_orders_what_is_missing_by_whatsapp_and_receives_the_order(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Dental Sur', 'contact_name' => 'Rocío', 'phone' => '+51987111222']);
        $anesthesia = Supply::factory()->for($supplier)->create(['name' => 'Lidocaína 2 %', 'unit' => 'carpule', 'min_stock' => 10]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageSupplies::class)
            ->assertActionHidden('placeOrder')
            ->callAction(TestAction::make('receive')->table($anesthesia), data: ['quantity' => 4, 'lot' => 'L-881', 'expires_on' => '2027-05-31'])
            ->assertHasNoFormErrors()
            ->assertNotified('Entrada registrada. Ahora hay 4 carpules.');

        $this->assertSame('L-881', $anesthesia->batches()->sole()->lot);

        // Quedó bajo el mínimo: el pedido ya viene armado con lo que falta para llegar al doble del mínimo.
        $component = Livewire::test(ManagePurchaseOrders::class)
            ->mountAction('placeOrder')
            ->assertSchemaStateSet(['supplier_id' => $supplier->id])
            ->assertSchemaStateSet(fn (array $state) => $this->assertEquals([['supply_id' => $anesthesia->id, 'quantity' => 16.0]], array_values($state['items'])))
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $order = PurchaseOrder::with('items')->sole();
        $this->assertSame(PurchaseOrderStatus::Sent, $order->status);

        // Ya pedido: no se vuelve a sugerir y en la lista se ve lo que viene.
        Livewire::test(ManageSupplies::class)
            ->assertActionHidden('placeOrder')
            ->assertSee('Pedido: llegan 16 carpules');
        $this->assertTrue(app(InventoryService::class)->toReorder()->isEmpty());
        $this->assertStringContainsString('https://wa.me/51987111222?text=', collect($component->effects['xjs'] ?? [])->pluck('expression')->implode(' '));

        Livewire::test(ManagePurchaseOrders::class)
            ->callAction(TestAction::make('receive')->table($order), data: ['items' => [$order->items->sole()->id => ['received' => 16, 'lot' => 'L-902', 'expires_on' => '2027-09-30']]])
            ->assertHasNoFormErrors()
            ->assertNotified('Pedido recibido. Ya está en el inventario.');

        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame(20.0, (float) $anesthesia->fresh()->stock);
        $this->assertFalse($anesthesia->fresh()->isLow());
    }

    public function test_the_doctor_writes_the_recipe_of_a_treatment_and_corrects_what_was_used_when_finishing(): void
    {
        $this->seed(CatalogSeeder::class);
        $implant = Treatment::where('slug', 'implante')->sole();
        $doctor = $this->doctorOffering($implant);
        $reception = User::factory()->reception()->create();
        $gloves = Supply::factory()->create(['name' => 'Guantes de nitrilo', 'unit' => 'par', 'min_stock' => 0]);
        $anesthesia = Supply::factory()->create(['name' => 'Lidocaína 2 %', 'unit' => 'carpule', 'min_stock' => 0]);
        $fixture = Supply::factory()->serialized()->create(['name' => 'Implante dental', 'min_stock' => 0]);
        app(InventoryService::class)->receive($gloves, 50, $reception);
        app(InventoryService::class)->receive($anesthesia, 20, $reception);
        app(InventoryService::class)->receiveSerials($fixture, ['IMP-001', 'IMP-002'], $reception, ['lot' => 'ST-55', 'expires_on' => '2030-01-31']);

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ManageTreatments::class)
            ->callAction(TestAction::make('supplies')->table($implant), data: ['supplies' => [
                ['supply_id' => $gloves->id, 'quantity' => 2],
                ['supply_id' => $anesthesia->id, 'quantity' => 3],
                ['supply_id' => $fixture->id, 'quantity' => 1],
            ]])
            ->assertHasNoFormErrors()
            ->assertNotified('Materiales guardados.');

        $appointment = Appointment::factory()->for($doctor)->for($implant)->at('2026-11-02 10:00')->confirmed()->create();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);
        $this->actingAs($doctor->user);

        $secondUnit = $fixture->batches()->where('serial', 'IMP-002')->sole();

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->mountAction('close')
            ->assertSchemaStateSet(fn (array $state) => $this->assertEquals([
                ['supply_id' => $gloves->id, 'quantity' => 2.0, 'batch_id' => null],
                ['supply_id' => $anesthesia->id, 'quantity' => 3.0, 'batch_id' => null],
                ['supply_id' => $fixture->id, 'quantity' => 1.0, 'batch_id' => null],
            ], array_values($state['supplies_used'])))
            // Usó un par de guantes más y una carpule menos; el implante, el de serie IMP-002.
            ->setActionData(['supplies_used' => $used = [
                ['supply_id' => $gloves->id, 'quantity' => 3, 'batch_id' => null],
                ['supply_id' => $anesthesia->id, 'quantity' => 2, 'batch_id' => null],
                ['supply_id' => $fixture->id, 'quantity' => 1, 'batch_id' => null],
            ]])
            ->callMountedAction()
            ->assertHasFormErrors(['supplies_used.2.batch_id' => 'required'])
            ->setActionData(['supplies_used' => array_replace_recursive($used, [2 => ['batch_id' => $secondUnit->id]])])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertRedirect(ConsultationResource::getUrl('view', ['record' => $consultation]));

        $this->assertSame(47.0, (float) $gloves->fresh()->stock);
        $this->assertSame(18.0, (float) $anesthesia->fresh()->stock);
        $this->assertSame(0.0, (float) $secondUnit->fresh()->remaining);
        $this->assertSame(1.0, (float) $fixture->batches()->where('serial', 'IMP-001')->sole()->remaining);

        $this->get(ConsultationResource::getUrl('view', ['record' => $consultation]))
            ->assertOk()
            ->assertSeeInOrder(['Materiales usados', 'Guantes de nitrilo: 3 pares', 'Lidocaína 2 %: 2 carpules', 'Implante dental: 1 unidad · Lote ST-55 · serie IMP-002']);

        $this->get(PatientResource::getUrl('clinical', ['record' => $appointment->patient]))
            ->assertOk()
            ->assertSeeInOrder(['Implantes colocados', '02/11/2026 · Implante dental · Lote ST-55 · serie IMP-002 · vence 31/01/2030', $doctor->name]);
    }

    public function test_without_inventory_finishing_a_consultation_is_just_a_confirmation(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->confirmed()->create();
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $doctor->user);
        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->mountAction('close')
            ->assertMountedActionModalDontSee('Materiales que usaste')
            ->callMountedAction()
            ->assertRedirect(ConsultationResource::getUrl('view', ['record' => $consultation]));

        $this->assertNotNull($consultation->fresh()->closed_at);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_hoy_warns_about_what_is_running_low_and_lots_about_to_expire(): void
    {
        $reception = User::factory()->reception()->create();
        $gloves = Supply::factory()->create(['name' => 'Guantes de nitrilo', 'unit' => 'par', 'min_stock' => 20]);
        $anesthesia = Supply::factory()->create(['name' => 'Lidocaína 2 %', 'unit' => 'carpule', 'min_stock' => 0]);
        app(InventoryService::class)->receive($gloves, 5, $reception);
        app(InventoryService::class)->receive($anesthesia, 10, $reception, ['lot' => 'L-1', 'expires_on' => '2026-11-20']);
        $this->actingAs($reception);

        Livewire::test(FollowUpsToday::class)
            ->assertSeeInOrder(['Materiales por pedir', 'Guantes de nitrilo. Pídelos por WhatsApp desde Inventario.'])
            ->assertSeeInOrder(['Lotes por vencer', 'Lidocaína 2 % (vence el 20/11). Úsalos primero.'])
            ->assertSeeHtml(e(SupplyResource::getUrl('index', ['tab' => 'low'])))
            ->assertSeeHtml(e(SupplyResource::getUrl('index', ['tab' => 'expiring'])));

        $this->get(Dashboard::getUrl())->assertOk();
    }
}
