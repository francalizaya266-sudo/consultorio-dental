<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Filament\Support\VisitCloseOut;
use App\Models\Appointment;
use App\Models\ConsentTemplate;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Supply;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class VisitCloseOutTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_finishing_reviews_what_is_often_forgotten_then_asks_reception_to_collect_and_suggests_the_next_session(): void
    {
        $this->freezeMonday();
        $endo = Treatment::factory()->create(['name' => 'Endodoncia']);
        $doctor = $this->doctorOffering($endo);
        $reception = User::factory()->reception()->create();
        $patient = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Huamán']);
        ConsentTemplate::create(['name' => 'Consentimiento de endodoncia', 'treatment_id' => $endo->id, 'body' => 'Endodoncia de {paciente}.']);
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::InProgress, 'accepted_at' => now()->subWeek()]);
        $item = $plan->items()->create(['treatment_id' => $endo->id, 'description' => 'Endodoncia', 'tooth' => '46', 'unit_price' => 400, 'sessions_planned' => 2, 'accepted_at' => now()->subWeek()]);
        $visit = Appointment::factory()->for($doctor)->for($endo)->for($patient)->at('2026-11-02 10:00')->create(['status' => AppointmentStatus::InProgress, 'treatment_plan_item_id' => $item->id]);
        $consultation = Consultation::factory()->for($patient)->for($doctor)->create(['appointment_id' => $visit->id, 'closed_at' => null]);
        $consultation->planItems()->attach($item);
        $this->actingAs($doctor->user);

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->mountAction('close')
            ->assertMountedActionModalSee(['Revisa antes de terminar', 'Falta el diagnóstico.', 'Falta firmar el consentimiento de endodoncia.'])
            ->callMountedAction()
            ->assertRedirect(ConsultationResource::getUrl('view', ['record' => $consultation]));

        // Arriba de la atención: lo que debe (el doctor no cobra: avisa) y la segunda sesión, a la semana.
        $nextMonday = CarbonImmutable::parse('2026-11-09');
        Livewire::test(ViewConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSeeInOrder(['Cobro', 'Debe S/ 200.00', 'Se cargó S/ 200.00: Endodoncia · pieza 46 · sesión 1 de 2', 'Avisar a recepción'])
            ->assertSeeInOrder(['Próxima cita', 'Próxima: Endodoncia · pieza 46 · sesión 2 de 2', 'Sugerida el lunes 9 de noviembre · A la semana', 'Agendar'])
            ->assertSeeHtml(e(AppointmentResource::nextVisitUrl($patient, $doctor->id, $nextMonday)))
            ->assertActionHidden('collect')
            ->call('mountAction', 'notifyReception')
            ->assertNotified('Listo: recepción ya sabe que tiene que cobrarle')
            ->assertSee('Recepción ya sabe que tiene que cobrarle.');

        $this->assertSame('Rosa Huamán terminó su atención: cobrarle', $reception->notifications()->sole()->data['title']);

        // Con la cita ya agendada, la tarjeta lo dice en vez de sugerir.
        Appointment::factory()->for($doctor)->for($endo)->for($patient)->at('2026-11-09 10:00')->confirmed()->create(['treatment_plan_item_id' => $item->id]);
        $this->assertSame('Ya tiene su próxima cita', VisitCloseOut::viewData($consultation->fresh(), $doctor->user)['next']['label']);

        // Quien maneja la caja (la administradora odontóloga) cobra ahí mismo.
        $this->assertSame('collect', VisitCloseOut::viewData($consultation->fresh(), User::factory()->admin()->create())['payment']['action']);

        // Al día siguiente la tarjeta ya no aparece.
        $this->travelTo(now()->addDay());
        $this->assertNull(VisitCloseOut::viewData($consultation->fresh(), $doctor->user));
    }

    public function test_the_materials_to_discount_say_how_much_is_left(): void
    {
        $reception = User::factory()->reception()->create();
        $gloves = Supply::factory()->create(['name' => 'Guantes de nitrilo', 'unit' => 'par', 'min_stock' => 0]);
        app(InventoryService::class)->receive($gloves, 3, $reception);

        $this->assertContains('Guantes de nitrilo · quedan 3 pares', collect(SupplyResource::pickerOptions(withStock: true))->flatten()->all());
        $this->assertContains('Guantes de nitrilo', collect(SupplyResource::pickerOptions())->flatten()->all());
    }
}
