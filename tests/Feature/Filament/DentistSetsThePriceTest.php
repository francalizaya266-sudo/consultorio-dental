<?php

namespace Tests\Feature\Filament;

use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Services\Billing\BillingService;
use App\Services\Clinical\ConsultationService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** Cada cosa hecha llega con su precio sugerido y el dentista lo cambia a su criterio; si no lo toca, se cobra el sugerido. */
class DentistSetsThePriceTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();

        foreach (['limpieza-dental' => 120, 'curacion-con-resina' => 150] as $slug => $price) {
            $this->treatment($slug)->update(['price_from' => $price]);
        }

        $this->doctor = $this->doctorOffering(...Treatment::all());
    }

    public function test_the_suggested_price_is_charged_when_the_dentist_does_not_change_it(): void
    {
        $resin = $this->treatment('curacion-con-resina');
        $appointment = $this->appointment($resin);
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $this->doctor->user);

        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSet('data.prices', ['treatment-'.$resin->id => '150.00'])
            ->callAction('close')
            ->assertHasNoFormErrors();

        $charge = Charge::query()->whereBelongsTo($appointment)->sole();
        $this->assertSame('150.00', $charge->amount);
        $this->assertNull($charge->suggested_amount);
        $this->assertNull($consultation->fresh()->prices);
    }

    public function test_the_dentist_charges_more_or_less_than_the_suggested_price(): void
    {
        $resin = $this->treatment('curacion-con-resina');
        $cleaning = $this->treatment('limpieza-dental');
        $appointment = $this->appointment($resin);
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $this->doctor->user);

        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->fillForm(['treatments' => [$resin->id, $cleaning->id]])
            ->assertSet('data.prices', ['treatment-'.$resin->id => '150.00', 'treatment-'.$cleaning->id => '120.00'])
            ->set('data.prices.treatment-'.$resin->id, '180')
            ->set('data.prices.treatment-'.$cleaning->id, '100')
            ->assertSee(['Sugerido S/ 150.00', 'Sugerido S/ 120.00', 'S/ 280.00', 'Sugerido S/ 270.00'])
            ->mountAction('close')
            ->assertActionMounted('close')
            ->assertMountedActionModalSee('Total S/ 280.00')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $charges = Charge::query()->whereBelongsTo($appointment)->orderBy('description')->get();
        $this->assertSame([['Curación con resina', '180.00', '150.00'], ['Limpieza dental', '100.00', '120.00']], $charges->map(fn (Charge $c) => [$c->description, $c->amount, $c->suggested_amount])->all());
        $this->assertEquals(['treatment-'.$resin->id => 180, 'treatment-'.$cleaning->id => 100], $consultation->fresh()->prices);
    }

    public function test_an_emptied_price_goes_back_to_the_suggested_one(): void
    {
        $resin = $this->treatment('curacion-con-resina');
        $consultation = app(ConsultationService::class)->startFromAppointment($this->appointment($resin), $this->doctor->user);

        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->set('data.prices.treatment-'.$resin->id, '')
            ->assertSet('data.prices.treatment-'.$resin->id, '150.00')
            ->set('data.prices.treatment-'.$resin->id, '90')
            ->call('save')
            ->assertHasNoFormErrors();

        // Lo que puso el dentista se guarda y vuelve al abrir la atención.
        $this->assertEquals(['treatment-'.$resin->id => 90], $consultation->fresh()->prices);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSet('data.prices.treatment-'.$resin->id, '90');
    }

    public function test_a_different_price_for_a_plan_session_does_not_move_to_the_next_sessions(): void
    {
        $resin = $this->treatment('curacion-con-resina');
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()]);
        $item = $plan->items()->create(['treatment_id' => $resin->id, 'description' => 'Curación con resina', 'unit_price' => 300, 'sessions_planned' => 3, 'accepted_at' => now()]);
        $appointment = Appointment::factory()->for($this->doctor)->for($resin)->for($patient)->confirmed()->at('2026-11-02 10:00')->create(['treatment_plan_item_id' => $item->id]);
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $this->doctor->user);

        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSet('data.prices', ['item-'.$item->id => '100.00'])
            ->set('data.prices.item-'.$item->id, '60')
            ->callAction('close')
            ->assertHasNoFormErrors();

        $billing = app(BillingService::class);
        $first = Charge::query()->whereBelongsTo($appointment)->sole();
        $this->assertSame(['60.00', '100.00'], [$first->amount, $first->suggested_amount]);

        // Las otras dos sesiones siguen a S/ 100: los S/ 40 que bajó el dentista no se cobran después.
        $this->assertSame('100.00', $billing->chargePlanSession($item->fresh(), $this->doctor->user)->amount);
        $this->assertSame('100.00', $billing->chargePlanSession($item->fresh(), $this->doctor->user)->amount);
        $this->assertSame(260.0, $billing->planProgress($plan->fresh())['total']);
    }

    private function treatment(string $slug): Treatment
    {
        return Treatment::where('slug', $slug)->firstOrFail();
    }

    private function appointment(Treatment $treatment): Appointment
    {
        return Appointment::factory()->for($this->doctor)->for($treatment)->for(Patient::factory()->create())->confirmed()->at('2026-11-02 10:00')->create();
    }
}
