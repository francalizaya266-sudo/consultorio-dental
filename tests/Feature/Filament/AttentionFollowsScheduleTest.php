<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Supply;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Clinical\ConsultationService;
use App\Services\Inventory\InventoryService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/** La atención parte de lo programado, el doctor confirma lo que hizo y solo eso se cobra y sale del inventario. */
class AttentionFollowsScheduleTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();

        foreach (['evaluacion-o-primera-consulta' => 50, 'limpieza-dental' => 120, 'curacion-con-resina' => 150, 'extraccion-simple' => 130] as $slug => $price) {
            $this->treatment($slug)->update(['price_from' => $price]);
        }

        $this->doctor = $this->doctorOffering(...Treatment::all());
    }

    public function test_what_was_scheduled_arrives_marked_and_what_was_not_done_is_not_charged_or_used(): void
    {
        $resin = $this->treatment('curacion-con-resina');
        $extraction = $this->treatment('extraccion-simple');
        $composite = $this->supply('Resina A2', $resin, 0.5);
        $gauze = $this->supply('Gasas', $extraction, 4);
        $appointment = $this->appointment($resin, '2026-11-02 10:00');

        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $this->doctor->user);
        $this->assertEquals([$resin->id], $consultation->treatments->modelKeys());

        $this->actingAs($this->doctor->user);

        // Iba a ser una resina, pero el doctor decidió extraer: cambia lo programado por lo que hizo.
        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['Lo que se hizo hoy', 'Al terminar se carga'])
            ->assertSet('data.prices', ['treatment-'.$resin->id => '150.00'])
            ->fillForm(['treatments' => [$extraction->id]])
            ->assertSet('data.prices', ['treatment-'.$extraction->id => '130.00'])
            ->callAction('close')
            ->assertHasNoFormErrors();

        $this->assertSame([['Extracción simple', '130.00']], $this->charges($appointment));
        $this->assertSame('96.00', $gauze->fresh()->stock);
        $this->assertSame('100.00', $composite->fresh()->stock);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        $this->get(ConsultationResource::getUrl('view', ['record' => $consultation]))->assertSee('Lo que se hizo')->assertSee('Extracción simple');
    }

    public function test_what_was_done_besides_the_schedule_is_added_and_charged(): void
    {
        $evaluation = $this->treatment('evaluacion-o-primera-consulta');
        $cleaning = $this->treatment('limpieza-dental');
        $appointment = $this->appointment($evaluation, '2026-11-02 10:00');
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $this->doctor->user);

        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->fillForm(['treatments' => [$evaluation->id, $cleaning->id]])
            ->assertSee('S/ 170.00')
            ->callAction('close');

        $this->assertEqualsCanonicalizing([['Evaluación o primera consulta', '50.00'], ['Limpieza dental', '120.00']], $this->charges($appointment));
    }

    public function test_marking_the_plan_session_of_the_same_treatment_charges_it_once_at_the_plan_price(): void
    {
        $resin = $this->treatment('curacion-con-resina');
        $appointment = $this->appointment($resin, '2026-11-02 10:00');
        $plan = TreatmentPlan::create(['patient_id' => $appointment->patient_id, 'doctor_id' => $this->doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()]);
        $first = $plan->items()->create(['treatment_id' => $resin->id, 'description' => 'Curación con resina', 'tooth' => '16', 'unit_price' => 110, 'accepted_at' => now()]);
        $plan->items()->create(['treatment_id' => $resin->id, 'description' => 'Curación con resina', 'tooth' => '26', 'unit_price' => 110, 'accepted_at' => now()]);

        // Con dos resinas pendientes no se sabe cuál es: llega aparte, y al marcar la del plan deja de ir aparte.
        $consultation = app(ConsultationService::class)->startFromAppointment($appointment, $this->doctor->user);
        $this->assertEquals([$resin->id], $consultation->treatments->modelKeys());
        $this->actingAs($this->doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->fillForm(['planItems' => [(string) $first->id]])
            ->assertSet('data.treatments', [])
            ->assertNotified('Curación con resina ya va como sesión del plan.')
            ->callAction('close');

        $this->assertSame([['Curación con resina · pieza 16', '110.00']], $this->charges($appointment));
        $this->assertSame(1, $first->fresh()->sessions_done);
    }

    public function test_an_appointment_left_open_on_an_earlier_day_can_still_be_registered(): void
    {
        $cleaning = $this->treatment('limpieza-dental');
        $friday = $this->appointment($cleaning, '2026-10-30 10:00');
        $this->actingAs($this->doctor->user);

        // El doctor la registra después: la atención y la cita quedan con la fecha y la hora programadas.
        Livewire::test(ViewAppointment::class, ['record' => $friday->getRouteKey()])
            ->assertActionVisible('start')
            ->assertActionHasLabel('start', 'Registrar atención')
            ->callAction('start')
            ->assertRedirect(ConsultationResource::getUrl('edit', ['record' => Consultation::sole()]));

        $consultation = Consultation::sole();
        $this->assertSame('2026-10-30 10:00', $consultation->attended_at->format('Y-m-d H:i'));

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])->callAction('close');

        $friday->refresh();
        $this->assertSame(AppointmentStatus::Completed, $friday->status);
        $this->assertSame('2026-10-30 10:00', $friday->started_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-30 10:30', $friday->finished_at->format('Y-m-d H:i'));
        $this->assertSame([['Limpieza dental', '120.00']], $this->charges($friday));

        // Recepción marca que vino a una que ni se confirmó; se carga lo programado.
        $saturday = $this->appointment($cleaning, '2026-10-31 11:00', confirmed: false);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewAppointment::class, ['record' => $saturday->getRouteKey()])
            ->assertActionHidden('start')
            ->assertActionHidden('confirm')
            ->assertActionVisible('noShow')
            ->callAction('complete');

        $this->assertSame(AppointmentStatus::Completed, $saturday->fresh()->status);
        $this->assertSame([['Limpieza dental', '120.00']], $this->charges($saturday));
    }

    public function test_the_next_appointment_comes_with_the_next_session_of_the_plan(): void
    {
        $resin = $this->treatment('curacion-con-resina');
        $extraction = $this->treatment('extraccion-simple');
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()]);
        $booked = $plan->items()->create(['treatment_id' => $resin->id, 'description' => 'Curación con resina', 'tooth' => '36', 'unit_price' => 150, 'sort_order' => 1]);
        $next = $plan->items()->create(['treatment_id' => $extraction->id, 'description' => 'Extracción simple', 'tooth' => '38', 'unit_price' => 130, 'sort_order' => 2]);
        Appointment::factory()->for($patient)->for($this->doctor)->for($resin)->at('2026-11-05 10:00')->confirmed()->create(['treatment_plan_item_id' => $booked->id]);

        // La resina ya tiene cita: lo siguiente por agendar es la extracción.
        $url = AppointmentResource::nextVisitUrl($patient, $this->doctor->id);
        $this->assertStringContainsString('plan_item='.$next->id, $url);
        $this->assertStringContainsString('treatment='.$extraction->id, $url);

        $this->actingAs($this->doctor->user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        Livewire::withQueryParams($query)
            ->test(CreateAppointment::class)
            ->assertSchemaStateSet(['patient_id' => $patient->id, 'treatment_plan_item_id' => $next->id, 'treatment_id' => $extraction->id]);
    }

    public function test_consultations_from_before_keep_what_was_charged(): void
    {
        $cleaning = $this->treatment('limpieza-dental');
        $resin = $this->treatment('curacion-con-resina');
        $loose = Consultation::factory()->for($this->doctor)->create(['appointment_id' => $this->appointment($cleaning, '2026-10-29 10:00')->id]);
        $plan = TreatmentPlan::create(['patient_id' => $loose->patient_id, 'doctor_id' => $this->doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted]);
        $item = $plan->items()->create(['treatment_id' => $resin->id, 'description' => 'Curación con resina', 'unit_price' => 150]);
        $session = Consultation::factory()->for($this->doctor)->create(['appointment_id' => Appointment::factory()->for($this->doctor)->for($resin)->at('2026-10-29 11:00')->create(['treatment_plan_item_id' => $item->id])->id]);
        $workedFromPlan = Consultation::factory()->for($this->doctor)->create(['appointment_id' => $this->appointment($resin, '2026-10-29 12:00')->id]);
        $workedFromPlan->planItems()->attach($item);
        $withoutAppointment = Consultation::factory()->for($this->doctor)->create();

        // Como si se corriera la migración sobre lo que ya había.
        Schema::drop('consultation_treatments');
        (require database_path('migrations/2026_11_06_000001_create_consultation_treatments_table.php'))->up();

        $this->assertEquals(
            [[$loose->id, $cleaning->id]],
            DB::table('consultation_treatments')->get()->map(fn (object $row) => [$row->consultation_id, $row->treatment_id])->all(),
        );
        $this->assertCount(0, $session->treatments);
        $this->assertCount(0, $workedFromPlan->treatments);
        $this->assertCount(0, $withoutAppointment->treatments);
        $this->assertInstanceOf(TreatmentPlanItem::class, $workedFromPlan->planItems->sole());
    }

    private function treatment(string $slug): Treatment
    {
        return Treatment::where('slug', $slug)->firstOrFail();
    }

    private function appointment(Treatment $treatment, string $startsAt, bool $confirmed = true): Appointment
    {
        $factory = Appointment::factory()->for($this->doctor)->for($treatment)->for(Patient::factory()->create())->at($startsAt);

        return ($confirmed ? $factory->confirmed() : $factory)->create();
    }

    /** Un material con 100 en stock que el tratamiento usa en cada atención. */
    private function supply(string $name, Treatment $treatment, float $quantity): Supply
    {
        $supply = Supply::factory()->create(['name' => $name, 'min_stock' => 0]);
        $treatment->supplies()->attach($supply->id, ['quantity' => $quantity]);
        app(InventoryService::class)->receive($supply, 100, User::factory()->reception()->create());

        return $supply;
    }

    /** @return list<array{0: string, 1: string}> Lo cargado por la cita, sin anulados: concepto y neto. */
    private function charges(Appointment $appointment): array
    {
        return Charge::query()->valid()->whereBelongsTo($appointment)->orderBy('id')->get()
            ->map(fn (Charge $charge) => [$charge->description, number_format($charge->net(), 2, '.', '')])
            ->all();
    }
}
