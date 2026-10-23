<?php

namespace Tests\Feature\Services\Reports;

use App\Enums\AppointmentStatus;
use App\Enums\DiscountType;
use App\Enums\PatientRelationship;
use App\Enums\PlanPaymentMode;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Clinical\ClinicalFileService;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\Reports\CampaignReport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class CampaignReportTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Treatment $cleaning;

    private Treatment $whitening;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        Storage::fake(ClinicalFileService::DISK);
        $this->cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $this->whitening = Treatment::factory()->create(['name' => 'Blanqueamiento', 'price_from' => 450]);
        $this->doctor = $this->doctorOffering($this->cleaning, $this->whitening);
    }

    public function test_a_discount_campaign_counts_its_patients_what_was_charged_and_the_discount(): void
    {
        $campaign = $this->campaign(['discount_type' => DiscountType::Percent, 'discount_value' => 20, 'treatment_id' => $this->whitening->id]);
        $newPatient = $this->campaignVisit($campaign, ['status' => AppointmentStatus::Completed, 'is_new_patient' => true]);
        $this->campaignVisit($campaign, ['status' => AppointmentStatus::Pending]);
        $this->campaignVisit($campaign, ['status' => AppointmentStatus::Cancelled]);

        app(BillingService::class)->chargeTreatment($newPatient, $this->doctor->user);

        $this->assertSame(
            ['booked' => 2, 'attended' => 1, 'new_patients' => 1, 'accepted' => 0, 'sold' => 360.0, 'discount' => 90.0],
            app(CampaignReport::class)->of($campaign),
        );
    }

    public function test_a_package_campaign_counts_who_accepted_it_and_its_slots_are_people_not_appointments(): void
    {
        $package = TreatmentPackage::factory()->create(['name' => 'Sonrisa en familia', 'price' => 500, 'for_two' => true, 'upfront_discount' => 20]);
        $package->items()->create(['treatment_id' => $this->cleaning->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 0]);
        $package->items()->create(['treatment_id' => $this->whitening->id, 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 1]);
        $campaign = $this->campaign(['treatment_package_id' => $package->id, 'max_redemptions' => 2]);

        $visit = $this->campaignVisit($campaign, ['status' => AppointmentStatus::Pending, 'starts_at' => now(), 'ends_at' => now()->addMinutes(30)]);
        $mother = $visit->patient;
        $daughter = Patient::factory()->dependentOf($mother->account)->create();
        // Otra reserva con la campaña no gasta cupo: lo gasta quien acepta el paquete.
        $this->campaignVisit($campaign, ['status' => AppointmentStatus::Pending]);

        $consultation = app(ConsultationService::class)->startFromAppointment($visit, $this->doctor->user);
        [$plan, $companionPlan] = app(TreatmentPlanService::class)->addPackageToDraft($consultation, $this->doctor->user, $package, $daughter);

        $this->assertSame([$campaign->id, $campaign->id], [$plan->campaign_id, $companionPlan->campaign_id]);
        $this->assertSame([0, true], [$campaign->usedSlots(), $campaign->hasSlotsLeft()]);

        app(TreatmentPlanService::class)->sign(
            $plan,
            ['payment_mode' => PlanPaymentMode::Upfront, 'upfront_discount' => 20],
            ['signer_name' => $mother->full_name, 'signer_relationship' => PatientRelationship::Self],
            $this->signature(),
            $this->doctor->user,
        );

        $this->assertSame([2, false], [$campaign->usedSlots(), $campaign->hasSlotsLeft()]);
        // Vendido: S/ 500 del paquete menos S/ 20 por pagar todo junto. Descuento: S/ 70 del paquete,
        // S/ 570 que no paga la hija y los S/ 20.
        $this->assertSame(
            ['booked' => 2, 'attended' => 0, 'new_patients' => 0, 'accepted' => 2, 'sold' => 480.0, 'discount' => 660.0],
            app(CampaignReport::class)->of($campaign),
        );

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListCampaigns::class)
            ->assertTableColumnStateSet('accepted', '2 / 2', $campaign)
            ->assertTableColumnStateSet('sold', 'S/ 480.00', $campaign)
            ->assertTableColumnHasDescription('sold', 'Descuento S/ 660.00', $campaign);
    }

    /** @param  array<string, mixed>  $attributes */
    private function campaign(array $attributes): Campaign
    {
        return Campaign::query()->create($attributes + [
            'name' => 'Sonrisa de verano',
            'slug' => 'sonrisa-verano-'.Campaign::count(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function campaignVisit(Campaign $campaign, array $attributes): Appointment
    {
        return Appointment::factory()->create($attributes + [
            'doctor_id' => $this->doctor->id,
            'treatment_id' => $this->whitening->id,
            'campaign_id' => $campaign->id,
        ]);
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
