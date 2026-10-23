<?php

namespace Tests\Feature\Services\Billing;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\TreatmentPlanStatus;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Charge;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Booking\AppointmentService;
use App\Services\Clinical\ConsultationService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
    }

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    private function consultations(): ConsultationService
    {
        return app(ConsultationService::class);
    }

    /** @return list<array{0: string, 1: string}> Concepto y monto a pagar de los cargos vigentes. */
    private function charges(Patient $patient): array
    {
        return $patient->charges()->valid()->orderBy('id')->get()->map(fn (Charge $c) => [$c->description, number_format($c->net(), 2)])->all();
    }

    public function test_each_session_of_the_plan_charges_its_part_and_the_last_one_what_is_left(): void
    {
        $endodontics = Treatment::where('slug', 'endodoncia')->firstOrFail();
        $doctor = $this->doctorOffering($endodontics);
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Accepted]);
        $endo = $plan->items()->create(['treatment_id' => $endodontics->id, 'description' => 'Endodoncia', 'tooth' => '36', 'unit_price' => 550, 'discount' => 50, 'sessions_planned' => 3]);

        foreach (range(1, 3) as $session) {
            $consultation = $this->consultations()->startWithoutAppointment($patient, $doctor->user);
            $consultation->planItems()->attach($endo);
            $this->consultations()->close($consultation, $doctor->user);
        }

        $this->assertSame([
            ['Endodoncia · pieza 36 · sesión 1 de 3', '166.67'],
            ['Endodoncia · pieza 36 · sesión 2 de 3', '166.67'],
            ['Endodoncia · pieza 36 · sesión 3 de 3', '166.66'],
        ], $this->charges($patient));
        $this->assertSame(500.0, $patient->balance());

        // Una sesión de más no se cobra otra vez: el presupuesto ya está completo.
        $extra = $this->consultations()->startWithoutAppointment($patient, $doctor->user);
        $extra->planItems()->attach($endo);
        $this->consultations()->close($extra, $doctor->user);

        $this->assertCount(3, $this->charges($patient));
    }

    public function test_correcting_a_consultation_does_not_charge_twice_and_voids_what_was_removed(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Accepted]);
        $resin = $plan->items()->create(['description' => 'Curación con resina', 'unit_price' => 150]);
        $extraction = $plan->items()->create(['description' => 'Extracción simple', 'unit_price' => 120]);

        $consultation = $this->consultations()->startWithoutAppointment($patient, $doctor->user);
        $consultation->planItems()->attach([$resin->id, $extraction->id]);
        $this->consultations()->close($consultation, $doctor->user);
        $this->assertSame(270.0, $patient->balance());

        // El doctor reabre: la extracción no se hizo hoy.
        $this->consultations()->reopen($consultation->fresh(), $doctor->user);
        $consultation->planItems()->detach($extraction->id);
        $this->consultations()->close($consultation->fresh(), $doctor->user);

        $this->assertSame([['Curación con resina', '150.00']], $this->charges($patient));
        $this->assertSame(BillingService::CORRECTED, Charge::where('treatment_plan_item_id', $extraction->id)->sole()->void_reason);

        // Y si vuelve a agregarla, se carga de nuevo.
        $this->consultations()->reopen($consultation->fresh(), $doctor->user);
        $consultation->planItems()->attach($extraction->id);
        $this->consultations()->close($consultation->fresh(), $doctor->user);

        $this->assertSame(270.0, $patient->balance());
    }

    public function test_an_appointment_outside_the_plan_charges_its_treatment_with_the_promotion(): void
    {
        $whitening = Treatment::where('slug', 'blanqueamiento')->firstOrFail();
        $whitening->update(['price_from' => 450]);
        $evaluation = Treatment::where('is_evaluation', true)->firstOrFail();
        $evaluation->update(['price_from' => 0]);
        $doctor = $this->doctorOffering($whitening, $evaluation);
        $campaign = Campaign::query()->create([
            'name' => 'Blanqueamiento 20%', 'slug' => 'blanco-20', 'treatment_id' => $whitening->id,
            'discount_type' => DiscountType::Percent, 'discount_value' => 20,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $patient = Patient::factory()->create();

        $appointment = Appointment::factory()->for($doctor)->for($whitening)->for($patient)->at('2026-11-02 10:00')->confirmed()->create(['campaign_id' => $campaign->id]);
        $this->consultations()->close($this->consultations()->startFromAppointment($appointment, $doctor->user), $doctor->user);

        $charge = Charge::sole();
        $this->assertSame(['450.00', '90.00', 'Promoción: Blanqueamiento 20%'], [$charge->amount, $charge->discount, $charge->discount_reason]);
        $this->assertTrue($charge->campaign->is($campaign));
        $this->assertSame(360.0, $patient->balance());

        // Una evaluación gratuita no genera cargo.
        $free = Appointment::factory()->for($doctor)->for($evaluation)->for($patient)->at('2026-11-02 11:00')->confirmed()->create();
        $this->consultations()->close($this->consultations()->startFromAppointment($free, $doctor->user), $doctor->user);

        $this->assertSame(1, Charge::count());
    }

    public function test_reception_completing_an_appointment_charges_it_once(): void
    {
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $doctor = $this->doctorOffering($cleaning);
        $appointment = Appointment::factory()->for($doctor)->for($cleaning)->at('2026-11-02 10:00')->confirmed()->create();
        $reception = User::factory()->reception()->create();

        app(AppointmentService::class)->markArrived($appointment, $reception);
        app(AppointmentService::class)->markCompleted($appointment->fresh(), $reception);
        $this->billing()->chargeAppointment($appointment->fresh(), $reception);

        $charge = Charge::sole();
        $this->assertSame(['Limpieza dental', '120.00'], [$charge->description, $charge->amount]);
        $this->assertTrue($charge->doctor->is($doctor));
        $this->assertTrue($charge->createdBy->is($reception));
    }

    public function test_at_checkout_reception_corrects_adds_and_removes_and_the_payment_settles_the_balance(): void
    {
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $doctor = $this->doctorOffering($cleaning);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->for($doctor)->for($cleaning)->for($patient)->at('2026-11-02 10:00')->create();
        $reception = User::factory()->reception()->create();
        // Debía S/ 40 de antes.
        Charge::factory()->for($patient)->create(['description' => 'Saldo anterior', 'amount' => 40]);

        $cleaningCharge = $this->billing()->chargeTreatment($appointment, $reception);
        $wrong = $this->billing()->addCharge($patient, ['description' => 'Flúor', 'amount' => 30], $reception, $appointment);

        $this->billing()->reviseCharges($appointment, [
            ['id' => $cleaningCharge->id, 'description' => 'Limpieza dental profunda', 'amount' => 150, 'discount' => 10],
            ['id' => null, 'description' => 'Radiografía periapical', 'amount' => 30, 'discount' => 0],
        ], $reception);

        $this->assertSame([
            ['Saldo anterior', '40.00'],
            ['Limpieza dental profunda', '140.00'],
            ['Radiografía periapical', '30.00'],
        ], $this->charges($patient));
        $this->assertSame('Quitado al cobrar', $wrong->fresh()->void_reason);
        $this->assertSame('Descuento al cobrar', $cleaningCharge->fresh()->discount_reason);
        $this->assertSame(210.0, $patient->balance());

        $payment = $this->billing()->registerPayment($patient, ['amount' => 200, 'method' => PaymentMethod::Yape->value, 'reference' => ' 00451 '], $reception, $appointment);

        $this->assertSame(['00451', $doctor->id, $cleaning->id], [$payment->reference, $payment->doctor_id, $payment->treatment_id]);
        $this->assertSame(10.0, $patient->balance());

        // Los anulados no cuentan.
        $payment->update(['voided_at' => now()]);
        $this->assertSame(210.0, $patient->balance());
    }

    public function test_patients_can_be_listed_with_their_balance(): void
    {
        $owes = Patient::factory()->create();
        $settled = Patient::factory()->create();
        $inFavor = Patient::factory()->create();
        Charge::factory()->for($owes)->create(['amount' => 120, 'discount' => 20]);
        Charge::factory()->for($owes)->create(['amount' => 500, 'voided_at' => now()]);
        Charge::factory()->for($settled)->create(['amount' => 80]);
        Payment::factory()->for($settled)->create(['amount' => 80]);
        Payment::factory()->for($inFavor)->create(['amount' => 50]);

        $this->assertSame([$owes->id], Patient::query()->owing()->pluck('id')->all());
        $this->assertSame(
            [$owes->id => '100.00', $settled->id => '0.00', $inFavor->id => '-50.00'],
            Patient::query()->withBalance()->get()->mapWithKeys(fn (Patient $p) => [$p->id => $p->balance_due])->all(),
        );
    }
}
