<?php

namespace Tests\Feature\Filament;

use App\Enums\ToothState;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\OdontogramCondition;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\OdontogramService;
use App\Services\Clinical\OdontogramSuggestions;
use App\Services\Clinical\TreatmentPlanService;
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

        // Con la curación de 26 aparte, el pago único y las cuotas se calculan sobre todo el plan.
        $options = ['Pago único de S/ 950.00 (con S/ 200.00 de descuento por pagar todo junto).', 'En cuotas: inicial de S/ 500.00 y 5 cuotas mensuales de S/ 130.00.'];
        $this->assertSame($options, app(TreatmentPlanService::class)->paymentOptions($plan));

        // Al paciente el paquete le llega en un renglón, con su precio y lo que incluye: su precio no se reparte a la vista.
        $whatsapp = app(WhatsAppOutbox::class)->draft(app(TreatmentPlanService::class)->whatsappDraft($plan));
        $this->assertStringContainsString("1. Paquete Ortodoncia: S/ 1,000.00. Incluye:\n   - Curación con resina en el ", $whatsapp);
        $this->assertStringContainsString("   - Control de ortodoncia × 2\n   - Colocación de brackets\n2. Curación con resina en el ", $whatsapp);
        $this->assertStringContainsString("Formas de pago:\n• ".$options[0], $whatsapp);

        app(ConsultationService::class)->close($this->evaluation, $this->doctor->user);

        Livewire::test(ViewConsultation::class, ['record' => $this->evaluation->getRouteKey()])
            ->assertSeeInOrder(['Presupuesto por aceptar: S/ 1,150.00', 'Paquete Ortodoncia · Curación con resina · pieza 26 (O) · vale hasta el', 'Formas de pago, se elige al firmar: pago único de S/ 950.00'])
            ->assertSeeInOrder(['Paquete Ortodoncia', 'S/ 1,000.00', 'Curación con resina · pieza 16 (O)', 'Por hacer', 'Control de ortodoncia', 'Colocación de brackets', 'Curación con resina · pieza 26 (O)', 'S/ 150.00'])
            ->assertSeeInOrder(['Formas de pago, se elige al firmar:', 'En cuotas: inicial de S/ 500.00 y 5 cuotas mensuales de S/ 130.00.', 'Total', 'S/ 1,150.00'])
            ->mountAction('acceptPlan')
            ->assertMountedActionModalSee(['Paquete Ortodoncia: S/ 1,000.00', 'Incluye: Curación con resina · pieza 16 (O) · Control de ortodoncia × 2 · Colocación de brackets', 'Curación con resina · pieza 26 (O): S/ 150.00', 'Total: S/ 1,150.00']);

        $this->get(TreatmentPlansRelationManager::printUrl($plan))
            ->assertOk()
            ->assertSeeInOrder(['Paquete Ortodoncia', 'Curación con resina', 'Control de ortodoncia', 'Colocación de brackets', 'S/ 1,000.00', 'Curación con resina', 'S/ 150.00', 'Total', 'S/ 1,150.00']);
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
}
