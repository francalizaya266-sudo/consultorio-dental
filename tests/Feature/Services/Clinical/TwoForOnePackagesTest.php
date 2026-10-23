<?php

namespace Tests\Feature\Services\Clinical;

use App\Enums\PatientRelationship;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\ClinicalException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
use App\Services\Billing\BillingService;
use App\Services\Clinical\ClinicalFileService;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\TreatmentPlanService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TwoForOnePackagesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $cleaning;

    private Treatment $whitening;

    private Doctor $doctor;

    private Patient $payer;

    private Patient $daughter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        Storage::fake(ClinicalFileService::DISK);
        $this->cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $this->whitening = Treatment::factory()->create(['name' => 'Blanqueamiento', 'price_from' => 480]);
        $this->doctor = $this->doctorOffering($this->cleaning, $this->whitening);
        $this->payer = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->daughter = Patient::factory()->dependentOf($this->payer->account)->create(['first_name' => 'Lucía', 'last_name' => 'Quispe']);
    }

    public function test_the_other_person_gets_the_same_treatments_at_no_cost_and_is_accepted_when_the_payer_signs(): void
    {
        $package = $this->smilePackage();
        $plan = $this->planFrom($package);
        $plans = app(TreatmentPlanService::class);

        $companionPlan = $plans->addCompanion($plan, $package, $this->daughter);

        $this->assertSame([$this->daughter->id, $plan->id, 'Sonrisa en familia (2x1)', TreatmentPlanStatus::Proposed], [$companionPlan->patient_id, $companionPlan->included_in_plan_id, $companionPlan->title, $companionPlan->status]);
        $this->assertSame(['Limpieza dental', 'Blanqueamiento'], $companionPlan->items->pluck('description')->all());
        $this->assertSame(0.0, $companionPlan->total());
        $this->assertNull($companionPlan->accepted_at);
        // En el plan de la otra persona no se ofrece el precio del paquete.
        $this->assertSame([null, []], [$companionPlan->offeredPackage(), $companionPlan->packageSummaries()]);
        $this->assertSame('Lucía Quispe', $plan->refresh()->companionNames());

        $plans->sign($plan, ['payment_mode' => PlanPaymentMode::Upfront], ['signer_name' => 'Rosa Quispe', 'signer_relationship' => PatientRelationship::Self], $this->signature(), $this->doctor->user);

        $companionPlan->refresh();
        $this->assertSame([TreatmentPlanStatus::Accepted, PlanPaymentMode::PerSession], [$companionPlan->status, $companionPlan->payment_mode]);
        $this->assertTrue($companionPlan->items->every(fn ($item) => $item->accepted_at !== null));
        $this->assertSame(500.0, (float) $this->payer->charges()->sole()->amount);

        // Las sesiones de la otra persona no se cobran.
        $this->assertNull(app(BillingService::class)->chargePlanSession($companionPlan->items->first(), $this->doctor->user));
        $this->assertSame(0, $this->daughter->charges()->count());
    }

    public function test_the_other_person_must_be_someone_else_and_the_package_a_two_for_one(): void
    {
        $plans = app(TreatmentPlanService::class);
        $package = $this->smilePackage();
        $plan = $this->planFrom($package);

        $this->assertThrows(fn () => $plans->addCompanion($plan, $package, $this->payer), ClinicalException::class);

        $package->update(['for_two' => false]);

        $this->assertThrows(fn () => $plans->addCompanion($plan, $package, $this->daughter), ClinicalException::class);
        $this->assertSame(1, TreatmentPlan::count());
    }

    public function test_if_the_payer_drops_the_plan_the_two_for_one_is_cancelled_too(): void
    {
        $package = $this->smilePackage();
        $plan = $this->planFrom($package);
        $companionPlan = app(TreatmentPlanService::class)->addCompanion($plan, $package, $this->daughter);

        app(TreatmentPlanService::class)->cancel($plan, 'se mudó');

        $companionPlan->refresh();
        $this->assertSame(TreatmentPlanStatus::Cancelled, $companionPlan->status);
        $this->assertSame('Cancelado: se canceló el 2x1 de Rosa Quispe', $companionPlan->notes);
    }

    public function test_from_the_consultation_the_package_goes_to_the_draft_and_the_other_person_gets_their_plan(): void
    {
        $plans = app(TreatmentPlanService::class);
        $package = $this->smilePackage();
        $consultation = app(ConsultationService::class)->startWithoutAppointment($this->payer, $this->doctor->user);

        [$plan, $companionPlan] = $plans->addPackageToDraft($consultation, $this->doctor->user, $package, $this->daughter);

        $this->assertSame(['Sonrisa en familia', TreatmentPlanStatus::Draft, 500.0], [$plan->title, $plan->status, $plan->load('items')->total()]);
        $this->assertSame([$plan->id, TreatmentPlanStatus::Draft], [$companionPlan->included_in_plan_id, $companionPlan->status]);

        // Un segundo paquete 2x1 en el mismo plan solo se suma: el 2x1 ya tiene a su otra persona.
        [, $again] = $plans->addPackageToDraft($consultation, $this->doctor->user, $package, $this->daughter);
        $this->assertNull($again);
        $this->assertSame(2, TreatmentPlan::count());

        // Lo que el doctor agregue en la atención de la otra persona va en un plan aparte, con su precio.
        $daughtersVisit = app(ConsultationService::class)->startWithoutAppointment($this->daughter, $this->doctor->user);
        [$daughtersPlan] = $plans->addToDraft($daughtersVisit, $this->doctor->user, [$plans->itemFor($this->cleaning)]);

        $this->assertNotSame($companionPlan->id, $daughtersPlan->id);
        $this->assertSame(120.0, $daughtersPlan->load('items')->total());
    }

    public function test_the_visit_of_the_other_person_shows_the_session_as_included_in_the_two_for_one(): void
    {
        $package = $this->smilePackage();
        $plan = $this->planFrom($package);
        $companionPlan = app(TreatmentPlanService::class)->addCompanion($plan, $package, $this->daughter);
        $appointment = Appointment::factory()->create([
            'patient_id' => $this->daughter->id,
            'doctor_id' => $this->doctor->id,
            'treatment_id' => $this->cleaning->id,
            'treatment_plan_item_id' => $companionPlan->items->first()->id,
        ]);

        $estimate = app(BillingService::class)->estimateFor($appointment);

        $this->assertSame([['label' => 'Limpieza dental · incluida en el 2x1 de Rosa Quispe', 'amount' => null]], $estimate['lines']);
        $this->assertEquals(0, $estimate['total']);
    }

    /** Limpieza y blanqueamiento (S/ 600 normal) a S/ 500, y con el mismo pago se atiende otra persona. */
    private function smilePackage(): TreatmentPackage
    {
        $package = TreatmentPackage::factory()->create(['name' => 'Sonrisa en familia', 'price' => 500, 'for_two' => true]);
        $package->items()->create(['treatment_id' => $this->cleaning->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 0]);
        $package->items()->create(['treatment_id' => $this->whitening->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 1]);

        return $package->fresh();
    }

    private function planFrom(TreatmentPackage $package): TreatmentPlan
    {
        $plan = TreatmentPlan::create(['patient_id' => $this->payer->id, 'doctor_id' => $this->doctor->id, 'title' => $package->name, 'status' => TreatmentPlanStatus::Proposed]);

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
