<?php

namespace Tests\Feature\Services\Clinical;

use App\Enums\PatientRelationship;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
use App\Services\Clinical\ClinicalFileService;
use App\Services\Clinical\TreatmentPlanService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TreatmentPackagesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $brackets;

    private Treatment $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->brackets = Treatment::factory()->create(['name' => 'Colocación de brackets', 'price_from' => 2500]);
        $this->control = Treatment::factory()->create(['name' => 'Control de ortodoncia', 'price_from' => 150]);
    }

    public function test_the_package_price_is_shared_among_its_treatments_so_the_plan_totals_exactly_the_package(): void
    {
        $package = $this->orthodontics();

        $this->assertSame([5200.0, 300.0], [$package->normalPrice(), $package->savings()]);

        $items = app(TreatmentPlanService::class)->itemsFromPackage($package);

        $this->assertSame(
            [
                ['Colocación de brackets', 1, 1, 2500.0, 144.23],
                ['Control de ortodoncia', 18, 18, 150.0, 155.77],
            ],
            array_map(fn (array $item) => [$item['description'], $item['quantity'], $item['sessions_planned'], $item['unit_price'], $item['discount']], $items),
        );
        $this->assertSame([$package->id, $package->id], array_column($items, 'treatment_package_id'));
        $this->assertSame(4900.0, round(array_sum(array_map(fn (array $item) => $item['quantity'] * $item['unit_price'] - $item['discount'], $items)), 2));
    }

    public function test_without_catalog_prices_the_package_price_is_shared_by_quantity(): void
    {
        $free = Treatment::factory()->create(['name' => 'Sesión de blanqueamiento', 'price_from' => null]);
        $package = TreatmentPackage::factory()->create(['price' => 100]);
        $package->items()->create(['treatment_id' => $free->id, 'quantity' => 3, 'sessions_planned' => 3]);

        [$item] = app(TreatmentPlanService::class)->itemsFromPackage($package);

        // 3 × S/ 33.34 menos S/ 0.02: el total es S/ 100 exactos.
        $this->assertSame([33.34, 0.02], [$item['unit_price'], $item['discount']]);
    }

    public function test_the_doctor_sets_the_monthly_amount_and_the_number_of_cuotas_follows(): void
    {
        $schedule = TreatmentPlanService::schedule(4900, PlanPaymentMode::Installments, ['down_payment' => 800, 'monthly_amount' => 250, 'installments' => 3], today());

        $this->assertCount(18, $schedule);
        $this->assertSame([250.0], array_values(array_unique(array_column(array_slice($schedule, 1, 16), 'amount'))));
        $this->assertSame([17, '2028-04-02', 100.0], [$schedule[17]['number'], $schedule[17]['due_on']->toDateString(), $schedule[17]['amount']]);
        $this->assertSame(
            'Inicial de S/ 800.00 y 17 cuotas mensuales: 16 de S/ 250.00 y la última de S/ 100.00.',
            TreatmentPlanService::scheduleText(PlanPaymentMode::Installments, $schedule, withDates: false),
        );

        // Una cuota tan baja que pasaría de 24 meses no vale.
        $this->assertThrows(fn () => TreatmentPlanService::schedule(4900, PlanPaymentMode::Installments, ['monthly_amount' => 100], today()));
    }

    public function test_the_package_says_how_it_can_be_paid(): void
    {
        $package = $this->orthodontics();

        $this->assertSame([
            'Pago único de S/ 4,600.00 (con S/ 300.00 de descuento por pagar todo junto).',
            'En cuotas: inicial de S/ 800.00 y 18 cuotas mensuales: 17 de S/ 228.00 y la última de S/ 224.00.',
        ], TreatmentPlanService::packagePaymentOptions($package));

        $package->update(['allows_upfront' => false, 'monthly_amount' => 250]);

        $this->assertSame(['En cuotas: inicial de S/ 800.00 y 17 cuotas mensuales: 16 de S/ 250.00 y la última de S/ 100.00.'], TreatmentPlanService::packagePaymentOptions($package));
    }

    public function test_a_plan_made_from_a_package_is_signed_with_the_package_price_and_cuotas(): void
    {
        Storage::fake(ClinicalFileService::DISK);
        $package = $this->orthodontics();
        $doctor = $this->doctorOffering($this->brackets, $this->control);
        $patient = Patient::factory()->create();
        $plan = $this->planFrom($package, $patient, $doctor);

        $this->assertSame($package->id, $plan->offeredPackage()?->id);
        $this->assertSame([['name' => 'Ortodoncia completa', 'normal' => 5200.0, 'price' => 4900.0, 'savings' => 300.0]], $plan->packageSummaries());

        app(TreatmentPlanService::class)->sign(
            $plan,
            ['payment_mode' => $package->defaultPaymentMode(), ...$package->terms()],
            ['signer_name' => $patient->full_name, 'signer_relationship' => PatientRelationship::Self],
            $this->signature(),
            $doctor->user,
        );

        $plan->refresh();
        $this->assertSame(PlanPaymentMode::Installments, $plan->payment_mode);
        $this->assertSame(4900.0, $plan->total());
        $this->assertSame(['Inicial', 'Cuota 1 de 18'], $plan->installments->take(2)->map->label()->all());
        $this->assertSame('Ortodoncia completa · inicial', $patient->charges()->sole()->description);
    }

    private function orthodontics(): TreatmentPackage
    {
        $package = TreatmentPackage::factory()->inInstallments(800, 18)->create([
            'name' => 'Ortodoncia completa',
            'price' => 4900,
            'upfront_discount' => 300,
        ]);
        $package->items()->create(['treatment_id' => $this->brackets->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 0]);
        $package->items()->create(['treatment_id' => $this->control->id, 'quantity' => 18, 'sessions_planned' => 18, 'sort_order' => 1]);

        return $package->fresh();
    }

    private function planFrom(TreatmentPackage $package, Patient $patient, Doctor $doctor): TreatmentPlan
    {
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => $package->name, 'status' => TreatmentPlanStatus::Proposed]);

        foreach (app(TreatmentPlanService::class)->itemsFromPackage($package) as $i => $item) {
            $plan->items()->create([...$item, 'sort_order' => $i]);
        }

        return $plan->load('items.package');
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
