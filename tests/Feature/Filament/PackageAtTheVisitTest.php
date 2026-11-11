<?php

namespace Tests\Feature\Filament;

use App\Enums\PatientRelationship;
use App\Enums\PlanItemStatus;
use App\Enums\PlanPaymentMode;
use App\Enums\ToothState;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\ClinicalException;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Resources\Patients\Pages\ViewClinicalRecord;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\OdontogramCondition;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\Billing\BillingService;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\OdontogramService;
use App\Services\Clinical\OdontogramSuggestions;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\Patients\PatientInsights;
use App\Services\WhatsApp\WhatsAppOutbox;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** El paquete en la evaluación: dice cómo se puede pagar y se queda con lo del diagnóstico que ya trae. */
class PackageAtTheVisitTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Doctor $doctor;

    private TreatmentPackage $orthodontics;

    private Consultation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();

        foreach (['curacion-con-resina' => 150, 'colocacion-de-brackets' => 2500, 'control-de-ortodoncia' => 80] as $slug => $price) {
            Treatment::where('slug', $slug)->update(['price_from' => $price]);
        }

        $this->doctor = $this->doctorOffering(Treatment::where('slug', 'curacion-con-resina')->firstOrFail());
        $this->orthodontics = TreatmentPackage::factory()->inInstallments(500, 5)->create([
            'name' => 'Ortodoncia',
            'price' => 1000,
            'upfront_discount' => 200,
            'monthly_amount' => 100,
        ]);

        foreach (['control-de-ortodoncia', 'control-de-ortodoncia', 'curacion-con-resina', 'colocacion-de-brackets'] as $order => $slug) {
            $this->orthodontics->items()->create(['treatment_id' => Treatment::where('slug', $slug)->value('id'), 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => $order]);
        }

        // La evaluación encontró caries en 16 y 26: dos curaciones en el borrador del plan.
        $patient = Patient::factory()->create();
        $this->evaluation = app(ConsultationService::class)->startWithoutAppointment($patient, $this->doctor->user);
        $caries = OdontogramCondition::where('code', 'caries')->firstOrFail();
        app(OdontogramService::class)->toggle($this->evaluation->odontogram, $caries, ToothState::Bad, 16, 'O');
        app(OdontogramService::class)->toggle($this->evaluation->odontogram, $caries, ToothState::Bad, 26, 'O');
        $suggestions = app(OdontogramSuggestions::class);
        $suggestions->apply($this->evaluation, $this->doctor->user, collect($suggestions->rows($this->evaluation->odontogram, $this->evaluation))->pluck('key')->all());
    }

    public function test_the_package_keeps_the_diagnosed_resin_it_already_includes_and_says_how_it_can_be_paid(): void
    {
        $resin16 = TreatmentPlanItem::where('tooth', '16')->sole();
        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->mountAction(TestAction::make('addPackage')->schemaComponent('plan'))
            ->fillForm(['package_id' => $this->orthodontics->id])
            ->assertSchemaStateSet(['included' => [(string) $resin16->id]], 'mountedActionSchema0')
            ->assertMountedActionModalSee([
                'Incluye: Control de ortodoncia × 2 · Curación con resina · Colocación de brackets.',
                'Formas de pago, se elige al aceptar y firmar:',
                'Pago único de S/ 800.00 (con S/ 200.00 de descuento por pagar todo junto).',
                'En cuotas: inicial de S/ 500.00 y 5 cuotas mensuales de S/ 100.00.',
                'Lo del diagnóstico que entra al paquete',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Se agregó Ortodoncia al plan (S/ 1,000.00).');

        // La curación del paquete es la de la pieza 16: no queda otra sin pieza. La de 26 se cobra aparte.
        $plan = TreatmentPlan::sole()->load('items');
        $this->assertSame(['16', '26', null, null, null], $plan->items->sortBy('sort_order')->pluck('tooth')->all());
        $this->assertSame($this->orthodontics->id, $resin16->fresh()->treatment_package_id);
        $this->assertSame(1000.0, round($plan->items->where('treatment_package_id', $this->orthodontics->id)->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2));
        $this->assertSame(1150.0, $plan->total());

        // El paquete conserva su inicial y sus cuotas; la curación de 26, que no es del paquete, se paga en su sesión.
        $options = [
            'Pago único de S/ 800.00 (con S/ 200.00 de descuento por pagar todo junto).',
            'En cuotas: inicial de S/ 500.00 y 5 cuotas mensuales de S/ 100.00.',
            'Lo que no es del paquete (S/ 150.00) se paga en cada sesión.',
        ];
        $this->assertSame($options, app(TreatmentPlanService::class)->paymentOptions($plan));

        // Al paciente el paquete le llega en un renglón, con su precio y lo que incluye: su precio no se reparte a la vista.
        $whatsapp = app(WhatsAppOutbox::class)->draft(app(TreatmentPlanService::class)->whatsappDraft($plan));
        $this->assertStringContainsString("1. Paquete Ortodoncia: S/ 1,000.00. Incluye:\n   - Curación con resina en el ", $whatsapp);
        $this->assertStringContainsString("   - Control de ortodoncia × 2, cada mes\n   - Colocación de brackets\n2. Curación con resina en el ", $whatsapp);
        $this->assertStringContainsString("Formas de pago:\n• ".implode("\n• ", $options), $whatsapp);

        app(ConsultationService::class)->close($this->evaluation, $this->doctor->user);

        $this->get(TreatmentPlansRelationManager::printUrl($plan))
            ->assertOk()
            ->assertSeeInOrder(['Paquete Ortodoncia', 'Curación con resina', 'Control de ortodoncia', 'Colocación de brackets', 'S/ 1,000.00', 'Curación con resina', 'S/ 150.00', 'Total', 'S/ 1,150.00', 'Formas de pago del paquete', ...$options]);

        Livewire::test(ViewConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->assertSeeInOrder(['Presupuesto por aceptar: S/ 1,150.00', 'Paquete Ortodoncia · Curación con resina · pieza 26 (O) · vale hasta el', 'Formas de pago, se elige al firmar: pago único de S/ 800.00'])
            ->assertSeeInOrder(['Paquete Ortodoncia', 'S/ 1,000.00', 'Curación con resina · pieza 16 (O)', 'Por hacer', 'Control de ortodoncia', 'Colocación de brackets', 'Curación con resina · pieza 26 (O)', 'S/ 150.00'])
            ->assertSeeInOrder(['Formas de pago, se elige al firmar:', $options[1], $options[2], 'Total', 'S/ 1,150.00'])
            ->mountAction('acceptPlan')
            ->assertMountedActionModalSee([
                'Paquete Ortodoncia: S/ 1,000.00',
                'Incluye: Curación con resina · pieza 16 (O) · Control de ortodoncia × 2, cada mes · Colocación de brackets',
                'Curación con resina · pieza 26 (O): S/ 150.00',
                'Total: S/ 1,150.00',
                'Lo que no es del paquete (S/ 150.00) no entra en el pago único ni en las cuotas: se paga en cada sesión.',
            ])
            ->assertSchemaStateSet(['payment_mode' => PlanPaymentMode::Installments, 'down_payment' => 500, 'monthly_amount' => 100], 'mountedActionSchema0')
            ->fillForm(['signature' => $this->signature()])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // Firmó en cuotas: las del paquete, tal como las anuncia. La curación de 26 queda fuera del cronograma.
        $plan->refresh()->load('items.plan.patient', 'installments');
        $this->assertSame([500.0, 100.0, 100.0, 100.0, 100.0, 100.0], $plan->installments->sortBy('number')->map(fn ($installment) => (float) $installment->amount)->values()->all());
        $resin26 = $plan->items->firstWhere('tooth', '26');
        $this->assertFalse($resin26->in_payment_schedule);
        $this->assertTrue($plan->items->where('treatment_package_id', $this->orthodontics->id)->every('in_payment_schedule'));
        $this->assertStringEndsWith('Lo demás (S/ 150.00) se paga en cada sesión.', app(TreatmentPlanService::class)->paymentText($plan));

        // La curación de 26 se cobra el día que se hace; la de 16 ya va en las cuotas.
        $billing = app(BillingService::class);
        $this->assertSame(150.0, $billing->chargePlanSession($resin26, $this->doctor->user)?->net());
        $this->assertNull($billing->chargePlanSession($plan->items->firstWhere('tooth', '16'), $this->doctor->user));
    }

    public function test_what_the_doctor_puts_inside_the_package_does_not_raise_its_price(): void
    {
        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->mountAction(TestAction::make('addPackage')->schemaComponent('plan'))
            ->fillForm(['package_id' => $this->orthodontics->id, 'included' => TreatmentPlanItem::pluck('id')->map(fn (int $id) => (string) $id)->all()])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $plan = TreatmentPlan::sole()->load('items');
        $this->assertSame(1000.0, $plan->total());
        $this->assertSame('Ortodoncia', $plan->title);
        $this->assertSame(['Pago único de S/ 800.00 (con S/ 200.00 de descuento por pagar todo junto).', 'En cuotas: inicial de S/ 500.00 y 5 cuotas mensuales de S/ 100.00.'], app(TreatmentPlanService::class)->paymentOptions($plan));
    }

    public function test_a_package_is_paid_at_once_or_in_installments_not_per_visit(): void
    {
        $this->actingAs($this->doctor->user);
        $plans = app(TreatmentPlanService::class);
        [$plan] = $plans->addPackageToDraft($this->evaluation, $this->doctor->user, $this->orthodontics);
        app(ConsultationService::class)->close($this->evaluation, $this->doctor->user);

        $this->assertSame([PlanPaymentMode::Upfront, PlanPaymentMode::Installments], TreatmentPlanService::paymentModes($plan->fresh()));

        Livewire::test(ViewConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->mountAction('acceptPlan')
            ->assertMountedActionModalSee(['Pago único', 'En cuotas', 'Es un paquete: se paga en un solo pago o en cuotas, no por cita.'])
            ->assertMountedActionModalDontSee(PlanPaymentMode::PerSession->getDescription());

        try {
            $plans->sign($plan->fresh(), ['payment_mode' => PlanPaymentMode::PerSession], ['signer_name' => 'Ana', 'signer_relationship' => PatientRelationship::Self], $this->signature(), $this->doctor->user);
            $this->fail('Un paquete no se firma por sesión.');
        } catch (ClinicalException $e) {
            $this->assertSame('Es un paquete: se paga en un solo pago o en cuotas, no por cita.', $e->getMessage());
        }

        // Al agendar su primera sesión, "ya lo aceptó" no lo deja por sesión: queda presentado, esperando su firma.
        $plans->accept($plan->fresh());
        $this->assertNull($plan->fresh()->accepted_at);
        $this->assertSame(TreatmentPlanStatus::Proposed, $plan->fresh()->status);
    }

    public function test_the_controls_follow_the_patients_brackets_and_the_package_says_how_long_it_lasts(): void
    {
        $package = TreatmentPackage::factory()->inInstallments(500, 18)->create(['name' => 'Ortodoncia', 'price' => 2000, 'duration_months' => 18]);
        $package->items()->create(['treatment_id' => Treatment::where('slug', 'colocacion-de-brackets')->value('id'), 'quantity' => 1, 'sessions_planned' => 1, 'sort_order' => 0]);
        $package->items()->create(['treatment_id' => Treatment::where('slug', 'control-de-ortodoncia')->value('id'), 'quantity' => 18, 'sessions_planned' => 18, 'sort_order' => 1]);
        $patient = Patient::factory()->create();
        $visit = app(ConsultationService::class)->startWithoutAppointment($patient, $this->doctor->user);
        $this->actingAs($this->doctor->user);

        // Sus brackets van a control cada 15 días: son 36 controles en vez de 18, al mismo precio.
        Livewire::test(EditConsultation::class, ['record' => $visit->getRouteKey()])
            ->mountAction(TestAction::make('addPackage')->schemaComponent('plan'))
            ->fillForm(['package_id' => $package->id])
            ->assertSchemaStateSet(['control_every' => 30], 'mountedActionSchema0')
            ->assertMountedActionModalSee(['Dura 18 meses.', 'Serán 18 controles; el precio del paquete no cambia.'])
            ->fillForm(['control_every' => 15])
            ->assertMountedActionModalSee('Serán 36 controles; el precio del paquete no cambia.')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $plan = TreatmentPlan::where('patient_id', $patient->id)->sole()->load('items');
        $controls = $plan->items->firstWhere('description', 'Control de ortodoncia');
        $this->assertSame([36, 36, 15], [(int) $controls->quantity, (int) $controls->sessions_planned, $controls->session_interval_days]);
        $this->assertSame(2000.0, $plan->total());
        $this->assertSame(['label' => 'Paquete Ortodoncia (18 meses)', 'contents' => ['Colocación de brackets', 'Control de ortodoncia × 36, cada 15 días']], collect(TreatmentPlan::lines($plan->items)->sole())->only(['label', 'contents'])->all());

        // Con los brackets puestos, el próximo control se sugiere a los 15 días.
        $plan->items->firstWhere('description', 'Colocación de brackets')->update(['status' => PlanItemStatus::Done, 'sessions_done' => 1]);
        $next = app(PatientInsights::class)->suggestedNextVisit($patient, evenIfNotAccepted: true);
        $this->assertTrue($next['item']->is($controls));
        $this->assertSame(today()->addDays(15)->toDateString(), $next['on']->toDateString());
        $this->assertSame('A los 15 días', $next['why']);
    }

    public function test_package_work_done_before_signing_is_not_charged_in_parts_and_waits_for_the_signature(): void
    {
        $this->actingAs($this->doctor->user);
        $plans = app(TreatmentPlanService::class);
        $resin16 = TreatmentPlanItem::where('tooth', '16')->sole();
        [$plan] = $plans->addPackageToDraft($this->evaluation, $this->doctor->user, $this->orthodontics, includedItemIds: [$resin16->id]);
        $brackets = $plan->items()->where('description', 'Colocación de brackets')->sole();
        $resin26 = $plan->items()->where('tooth', '26')->sole();

        // El doctor coloca los brackets y cura las dos piezas el mismo día, antes de que el paciente firme.
        $this->evaluation->planItems()->sync([$brackets->id, $resin16->id, $resin26->id]);
        $lines = collect(app(BillingService::class)->previewConsultation($this->evaluation, [], [$brackets->id, $resin16->id, $resin26->id]));

        // El paquete va en una línea, sin su parte del precio; lo que no es del paquete, con el suyo.
        $this->assertSame(['Paquete Ortodoncia: Curación con resina · pieza 16 (O), Colocación de brackets', 'Curación con resina · pieza 26 (O)'], $lines->pluck('label')->all());
        $this->assertSame([null, 150.0], $lines->pluck('amount')->all());

        Livewire::test(EditConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->assertSee(['Con el paquete, al firmar', 'Falta que firme el paquete.'])
            ->assertDontSee('769.23');

        app(ConsultationService::class)->close($this->evaluation->fresh(), $this->doctor->user);

        // Solo se cargó la curación aparte. El plan no quedó "por sesión": espera la firma.
        $this->assertSame([150.0], $this->evaluation->patient->charges()->get()->map->net()->all());
        $plan->refresh();
        $this->assertSame([TreatmentPlanStatus::InProgress, null, null], [$plan->status, $plan->accepted_at, $plan->payment_mode]);

        Livewire::test(ViewConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->assertSee(['Ya empezó su paquete, falta que firme: S/ 1,150.00', 'Ya se empezó y falta que firme cómo pagará el paquete.'])
            ->mountAction('acceptPlan')
            ->fillForm(['signature' => $this->signature()])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // Firmó en cuotas: lo hecho del paquete queda cubierto por ellas, y hoy se carga la inicial.
        $plan->refresh()->load('items', 'installments');
        $this->assertSame([TreatmentPlanStatus::InProgress, PlanPaymentMode::Installments], [$plan->status, $plan->payment_mode]);
        $this->assertTrue($plan->items->where('treatment_package_id', $this->orthodontics->id)->every('in_payment_schedule'));
        $this->assertEqualsCanonicalizing([150.0, 500.0], $this->evaluation->patient->charges()->get()->map->net()->all());

        Livewire::test(ViewConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->assertSee('Forma de pago que firmó:')
            ->assertSee('En cuotas. Inicial de S/ 500.00');
    }

    public function test_adding_the_package_again_puts_what_is_marked_inside_it_instead_of_charging_it_twice(): void
    {
        $this->actingAs($this->doctor->user);
        $plans = app(TreatmentPlanService::class);
        $resin16 = TreatmentPlanItem::where('tooth', '16')->sole();
        $resin26 = TreatmentPlanItem::where('tooth', '26')->sole();
        [$plan] = $plans->addPackageToDraft($this->evaluation, $this->doctor->user, $this->orthodontics, includedItemIds: [$resin16->id]);

        Livewire::test(EditConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->mountAction(TestAction::make('addPackage')->schemaComponent('plan'))
            ->fillForm(['package_id' => $this->orthodontics->id])
            ->assertMountedActionModalSee(['Su plan ya tiene este paquete: no se agrega otra vez.', 'Lo que entra al paquete, sin costo'])
            ->assertMountedActionModalDontSee('Formas de pago, se elige al aceptar y firmar:')
            ->fillForm(['included' => [(string) $resin26->id]])
            ->assertMountedActionModalSee('Se deja de cobrar S/ 150.00: Curación con resina · pieza 26 (O).')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Entró al paquete, sin costo: Curación con resina · pieza 26 (O).');

        // Un solo paquete, al mismo precio, ahora con la curación de 26 adentro.
        $plan->refresh()->load('items');
        $this->assertSame([1000.0, 5], [$plan->total(), $plan->items->count()]);
        $this->assertSame([$this->orthodontics->id, 0.0], [$resin26->fresh()->treatment_package_id, $resin26->fresh()->subtotal()]);

        // Lo que aparece después también puede ir dentro del paquete, a criterio del doctor.
        $cleaning = Treatment::where('slug', 'limpieza-dental')->firstOrFail();
        Livewire::test(EditConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->mountAction(TestAction::make('addToPlan')->schemaComponent('plan'))
            ->fillForm(['treatment_id' => $cleaning->id, 'unit_price' => 80, 'in_package' => true])
            ->assertMountedActionModalSee('Entra en su paquete Ortodoncia, sin costo')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1000.0, $plan->fresh()->total());
        $this->assertSame($this->orthodontics->id, $plan->items()->where('treatment_id', $cleaning->id)->sole()->treatment_package_id);
    }

    public function test_after_signing_what_goes_into_the_package_costs_nothing_and_needs_no_new_signature(): void
    {
        $this->actingAs($this->doctor->user);
        $plans = app(TreatmentPlanService::class);
        [$plan] = $plans->addPackageToDraft($this->evaluation, $this->doctor->user, $this->orthodontics);
        $plans->sign($plan->fresh(), ['payment_mode' => PlanPaymentMode::Installments, ...$this->orthodontics->terms()], ['signer_name' => 'Ana', 'signer_relationship' => PatientRelationship::Self], $this->signature(), $this->doctor->user);

        // Las dos curaciones del diagnóstico se firmaron aparte, por sesión. El doctor decide meter la de 26.
        $resin26 = TreatmentPlanItem::where('tooth', '26')->sole();
        $this->assertFalse($resin26->in_payment_schedule);

        Livewire::test(TreatmentPlansRelationManager::class, ['ownerRecord' => $this->evaluation->patient, 'pageClass' => ViewClinicalRecord::class])
            ->mountAction(TestAction::make('includeInPackage')->table($plan))
            ->fillForm(['items' => [(string) $resin26->id]])
            ->assertMountedActionModalSee('Se deja de cobrar S/ 150.00: Curación con resina · pieza 26 (O).')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $resin26->refresh()->load('plan');
        $this->assertSame([$this->orthodontics->id, 0.0, true], [$resin26->treatment_package_id, $resin26->subtotal(), $resin26->in_payment_schedule]);
        $this->assertNull(app(BillingService::class)->chargePlanSession($resin26, $this->doctor->user));
        // Las cuotas no cambian y no queda nada por firmar de ella.
        $this->assertSame(1000.0, round($plan->installments()->sum('amount'), 2));
        $this->assertNotContains($resin26->id, $plan->fresh()->itemsToAccept()->pluck('id')->all());
    }

    public function test_the_plan_form_shows_the_package_with_one_price_and_spreads_it_by_itself(): void
    {
        $this->actingAs($this->doctor->user);
        [$plan] = app(TreatmentPlanService::class)->addPackageToDraft($this->evaluation, $this->doctor->user, $this->orthodontics);

        Livewire::test(TreatmentPlansRelationManager::class, ['ownerRecord' => $this->evaluation->patient, 'pageClass' => ViewClinicalRecord::class])
            ->mountAction(TestAction::make('edit')->table($plan))
            ->assertSchemaStateSet(['package_price' => '1000.00'])
            ->assertMountedActionModalSee(['Precio del paquete', 'Colocación de brackets · en el paquete'])
            ->assertMountedActionModalDontSee('847.46')
            ->fillForm(['package_price' => 900])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // El paquete baja a 900 y lo que no es del paquete sigue con su precio.
        $plan->refresh()->load('items');
        $this->assertSame(900.0, round($plan->items->where('treatment_package_id', $this->orthodontics->id)->sum(fn (TreatmentPlanItem $item) => $item->subtotal()), 2));
        $this->assertSame(1200.0, $plan->total());
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
