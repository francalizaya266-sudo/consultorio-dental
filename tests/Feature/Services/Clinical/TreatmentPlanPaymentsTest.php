<?php

namespace Tests\Feature\Services\Clinical;

use App\Enums\PatientRelationship;
use App\Enums\PaymentMethod;
use App\Enums\PlanItemStatus;
use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\ClinicalException;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Services\Billing\BillingService;
use App\Services\Clinical\ClinicalFileService;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\TreatmentPlanService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TreatmentPlanPaymentsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Doctor $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        Storage::fake(ClinicalFileService::DISK);

        $this->doctor = $this->doctorOffering(...Treatment::all());
        $this->patient = Patient::factory()->create();
    }

    public function test_once_signed_the_agreed_prices_no_longer_change_and_additions_are_signed_apart(): void
    {
        $plan = $this->plan([['Curación con resina', 'curacion-con-resina', 150, 1]]);

        $signature = $this->plans()->sign($plan, ['payment_mode' => PlanPaymentMode::PerSession], $this->signer(), $this->signature(), $this->doctor->user);

        $plan->refresh();
        $item = $plan->items->sole();
        $this->assertSame([TreatmentPlanStatus::Accepted, PlanPaymentMode::PerSession], [$plan->status, $plan->payment_mode]);
        $this->assertNotNull($item->accepted_at);
        $this->assertFalse($item->in_payment_schedule);
        $this->assertSame('150.00', $signature->total);
        $this->assertStringContainsString('Forma de pago: Por sesión.', $signature->summary);
        Storage::disk(ClinicalFileService::DISK)->assertExists($signature->signature_path);

        // El precio firmado no se cambia ni se quita: se cancela.
        $this->assertThrows(fn () => $item->update(['unit_price' => 120]), ClinicalException::class);
        $this->assertThrows(fn () => $item->delete(), ClinicalException::class);
        $item->refresh()->update(['tooth' => '46', 'status' => PlanItemStatus::Cancelled]);

        // Lo que se agrega después queda por aceptar hasta que el paciente lo firma.
        $plan->items()->create(['treatment_id' => $this->treatment('endodoncia')->id, 'description' => 'Endodoncia', 'tooth' => '46', 'unit_price' => 450]);
        $this->assertSame(['Endodoncia'], $plan->fresh('items')->itemsToAccept()->pluck('description')->all());

        $addition = $this->plans()->sign($plan->fresh(), [], $this->signer(), $this->signature(), $this->doctor->user);

        $this->assertSame('450.00', $addition->total);
        $this->assertSame([], $plan->fresh('items')->itemsToAccept()->all());
        $this->assertCount(2, $plan->signatures);
        $this->assertThrows(fn () => $this->plans()->sign($plan->fresh(), [], $this->signer(), $this->signature(), $this->doctor->user), ClinicalException::class);
    }

    public function test_installments_are_whole_soles_once_a_month_and_the_last_one_adjusts(): void
    {
        $schedule = TreatmentPlanService::schedule(4900, PlanPaymentMode::Installments, ['down_payment' => 800, 'installments' => 18, 'first_due_on' => '2026-12-02'], today());

        $this->assertCount(19, $schedule);
        $this->assertSame([0, '2026-11-02', 800.0], [$schedule[0]['number'], $schedule[0]['due_on']->toDateString(), $schedule[0]['amount']]);
        $this->assertSame([228.0], array_values(array_unique(array_column(array_slice($schedule, 1, 17), 'amount'))));
        $this->assertSame([18, '2028-05-02', 224.0], [$schedule[18]['number'], $schedule[18]['due_on']->toDateString(), $schedule[18]['amount']]);
        $this->assertSame(4900.0, array_sum(array_column($schedule, 'amount')));
        $this->assertSame(
            'Inicial de S/ 800.00 el 02/11/2026 y 17 cuotas de S/ 228.00 y la última de S/ 224.00, del 02/12/2026 al 02/05/2028.',
            TreatmentPlanService::scheduleText(PlanPaymentMode::Installments, $schedule),
        );

        // El 31 cae en el último día de los meses cortos.
        $this->assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31'],
            collect(TreatmentPlanService::schedule(300, PlanPaymentMode::Installments, ['installments' => 3, 'first_due_on' => '2027-01-31'], today()))->map(fn (array $row) => $row['due_on']->toDateString())->all(),
        );

        $this->assertThrows(fn () => TreatmentPlanService::schedule(500, PlanPaymentMode::Installments, ['down_payment' => 500, 'installments' => 3], today()), ClinicalException::class);
        $this->assertThrows(fn () => TreatmentPlanService::schedule(500, PlanPaymentMode::Installments, ['installments' => 25], today()), ClinicalException::class);
    }

    public function test_in_installments_each_payment_is_charged_when_due_and_the_sessions_are_not_charged_apart(): void
    {
        $plan = $this->plan([['Colocación de brackets', 'colocacion-de-brackets', 1800, 1], ['Control de ortodoncia', 'control-de-ortodoncia', 200, 2]], 'Ortodoncia');

        $this->plans()->sign($plan, ['payment_mode' => 'installments', 'down_payment' => 600, 'installments' => 3, 'first_due_on' => '2026-12-02'], $this->signer(), $this->signature(), $this->doctor->user);

        // La inicial vence hoy y se carga al firmar.
        $this->assertSame([['Ortodoncia · inicial', '600.00']], $this->charges());
        $this->assertTrue($plan->fresh('items')->items->every->in_payment_schedule);

        // Lo trabajado en la atención no se carga aparte: ya está en las cuotas.
        $consultation = $this->consultations()->startWithoutAppointment($this->patient, $this->doctor->user);
        $consultation->planItems()->attach($plan->items->first());
        $this->consultations()->close($consultation, $this->doctor->user);
        $this->assertCount(1, $this->charges());

        $this->pay(600);
        $this->travelTo(CarbonImmutable::parse('2027-01-05 09:00', 'America/Lima'));
        $this->assertSame(2, $this->billing()->chargeDueInstallments());
        $this->assertSame(0, $this->billing()->chargeDueInstallments());

        $this->assertSame([['Ortodoncia · inicial', '600.00'], ['Ortodoncia · cuota 1 de 3', '467.00'], ['Ortodoncia · cuota 2 de 3', '467.00']], $this->charges());
        $this->assertSame('2026-12-02', Charge::where('description', 'Ortodoncia · cuota 1 de 3')->sole()->charged_at->toDateString());

        // Los pagos cubren primero lo más antiguo.
        $this->pay(467);
        $this->assertSame(
            [['Inicial', 'paid', 0.0], ['Cuota 1 de 3', 'paid', 0.0], ['Cuota 2 de 3', 'late', 467.0], ['Cuota 3 de 3', 'upcoming', 466.0]],
            collect($this->billing()->installmentStates($plan->fresh()))->map(fn (array $row) => [$row['installment']->label(), $row['state'], $row['due']])->all(),
        );
    }

    public function test_upfront_charges_everything_today_with_the_discount_for_paying_at_once(): void
    {
        $plan = $this->plan([['Limpieza dental', 'limpieza-dental', 130, 1], ['Curación con resina', 'curacion-con-resina', 150, 2]]);

        $signature = $this->plans()->sign($plan, ['payment_mode' => PlanPaymentMode::Upfront, 'upfront_discount' => 30], $this->signer(), $this->signature(), $this->doctor->user);

        $this->assertSame([['Plan de tratamiento · pago único', '250.00']], $this->charges());
        $this->assertSame(['280.00', '30.00'], [Charge::sole()->amount, Charge::sole()->discount]);
        $this->assertStringContainsString('Pago único de S/ 250.00 el 02/11/2026 (con S/ 30.00 de descuento por pagar todo junto).', $signature->summary);
    }

    public function test_what_is_added_later_to_a_plan_in_installments_is_charged_per_session(): void
    {
        $plan = $this->plan([['Implante', 'implante', 3000, 1]]);
        $this->plans()->sign($plan, ['payment_mode' => 'installments', 'installments' => 6], $this->signer(), $this->signature(), $this->doctor->user);

        $extra = $plan->items()->create(['treatment_id' => $this->treatment('endodoncia')->id, 'description' => 'Endodoncia', 'tooth' => '26', 'unit_price' => 450]);
        $this->plans()->sign($plan->fresh(), [], $this->signer(), $this->signature(), $this->doctor->user);
        $this->assertFalse($extra->fresh()->in_payment_schedule);

        $consultation = $this->consultations()->startWithoutAppointment($this->patient, $this->doctor->user);
        $consultation->planItems()->attach($extra);
        $this->consultations()->close($consultation, $this->doctor->user);

        $this->assertSame([['Endodoncia · pieza 26', '450.00']], $this->charges());
    }

    public function test_cancelling_a_plan_in_installments_stops_its_cuotas_and_can_charge_what_was_done(): void
    {
        $plan = $this->plan([['Colocación de brackets', 'colocacion-de-brackets', 1800, 1], ['Control de ortodoncia', 'control-de-ortodoncia', 1200, 6]], 'Ortodoncia');
        $this->plans()->sign($plan, ['payment_mode' => 'installments', 'down_payment' => 300, 'installments' => 10], $this->signer(), $this->signature(), $this->doctor->user);

        foreach ([0, 1] as $index) {
            $consultation = $this->consultations()->startWithoutAppointment($this->patient, $this->doctor->user);
            $consultation->planItems()->attach($plan->items->get($index));
            $this->consultations()->close($consultation, $this->doctor->user);
        }

        // Hecho: los brackets y 1 de 6 controles. Cargado: solo la inicial.
        $this->assertSame(['done' => 2000.0, 'charged' => 300.0], $this->plans()->scheduleProgress($plan->fresh()));

        $this->plans()->cancel($plan->fresh(), 'Se mudó de ciudad', $this->doctor->user);
        $this->travelTo(now()->addMonths(3));
        $this->billing()->chargeDueInstallments();

        $this->assertSame([['Ortodoncia · inicial', '300.00'], ['Ortodoncia · lo hecho que faltaba cobrar', '1700.00']], $this->charges());
        $this->assertSame(TreatmentPlanStatus::Cancelled, $plan->fresh()->status);
    }

    public function test_the_appointment_says_what_will_be_charged_that_day(): void
    {
        $plan = $this->plan([['Colocación de brackets', 'colocacion-de-brackets', 1800, 1], ['Control de ortodoncia', 'control-de-ortodoncia', 200, 2]], 'Ortodoncia');
        $this->plans()->sign($plan, ['payment_mode' => 'installments', 'installments' => 3, 'first_due_on' => '2026-11-10'], $this->signer(), $this->signature(), $this->doctor->user);
        $control = $plan->items->get(1);

        $appointment = Appointment::factory()->for($this->patient)->for($this->doctor)->create([
            'treatment_id' => $control->treatment_id,
            'treatment_plan_item_id' => $control->id,
            'starts_at' => '2026-11-12 10:00',
            'ends_at' => '2026-11-12 10:30',
        ]);

        $this->assertSame(
            ['lines' => [
                ['label' => 'Cuota 1 de 3 · Ortodoncia · vence 10/11', 'amount' => 667.0],
                ['label' => 'Control de ortodoncia · sesión 1 de 2 · incluida en el plan (en cuotas)', 'amount' => null],
            ], 'total' => 667.0],
            $this->billing()->estimateFor($appointment),
        );

        // Una cita suelta: el precio de lista, con la promoción con que reservó.
        $resin = $this->treatment('curacion-con-resina');
        $resin->update(['price_from' => 150]);
        Charge::factory()->for($this->patient)->create(['description' => 'Radiografía', 'amount' => 40]);
        $single = Appointment::factory()->for($this->patient)->for($this->doctor)->for($resin)->create(['starts_at' => '2026-11-04 10:00', 'ends_at' => '2026-11-04 11:00']);

        $this->assertSame(
            ['lines' => [['label' => 'Saldo pendiente (Radiografía)', 'amount' => 40.0], ['label' => 'Curación con resina', 'amount' => 150.0]], 'total' => 190.0],
            $this->billing()->estimateFor($single),
        );
    }

    public function test_the_appointment_treatment_is_not_charged_again_when_it_is_worked_from_the_plan(): void
    {
        $control = $this->treatment('control-de-ortodoncia');
        $control->update(['price_from' => 200]);
        $plan = $this->plan([['Control de ortodoncia', 'control-de-ortodoncia', 180, 3]]);
        $this->plans()->sign($plan, ['payment_mode' => PlanPaymentMode::PerSession], $this->signer(), $this->signature(), $this->doctor->user);

        // La cita se agendó sin elegir la sesión del plan, pero el doctor marcó el control del plan.
        $appointment = Appointment::factory()->confirmed()->for($this->patient)->for($this->doctor)->for($control)->create(['starts_at' => '2026-11-02 10:00', 'ends_at' => '2026-11-02 10:30']);
        $this->travelTo('2026-11-02 10:00');
        $consultation = $this->consultations()->startFromAppointment($appointment, $this->doctor->user);
        $consultation->planItems()->attach($plan->items->sole());
        $this->consultations()->close($consultation, $this->doctor->user);

        $this->assertSame([['Control de ortodoncia · sesión 1 de 3', '60.00']], $this->charges());
    }

    /** @param  list<array{0: string, 1: string, 2: float, 3: int}>  $items  Descripción, tratamiento, precio y sesiones. */
    private function plan(array $items, ?string $title = null): TreatmentPlan
    {
        $plan = TreatmentPlan::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'title' => $title, 'status' => TreatmentPlanStatus::Proposed]);

        foreach ($items as $i => [$description, $slug, $price, $sessions]) {
            $plan->items()->create(['treatment_id' => $this->treatment($slug)->id, 'description' => $description, 'unit_price' => $price, 'sessions_planned' => $sessions, 'sort_order' => $i]);
        }

        return $plan->load('items');
    }

    private function treatment(string $slug): Treatment
    {
        return Treatment::where('slug', $slug)->firstOrFail();
    }

    /** @return list<array{0: string, 1: string}> */
    private function charges(): array
    {
        return $this->patient->charges()->valid()->orderBy('charged_at')->orderBy('id')->get()->map(fn (Charge $c) => [$c->description, number_format($c->net(), 2, '.', '')])->all();
    }

    private function pay(float $amount): void
    {
        $this->billing()->registerPayment($this->patient, ['amount' => $amount, 'method' => PaymentMethod::Cash], $this->doctor->user);
    }

    /** @return array{signer_name: string, signer_document: string|null, signer_relationship: PatientRelationship} */
    private function signer(): array
    {
        return ['signer_name' => $this->patient->full_name, 'signer_document' => '45879632', 'signer_relationship' => PatientRelationship::Self];
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

    private function plans(): TreatmentPlanService
    {
        return app(TreatmentPlanService::class);
    }

    private function billing(): BillingService
    {
        return app(BillingService::class);
    }

    private function consultations(): ConsultationService
    {
        return app(ConsultationService::class);
    }
}
