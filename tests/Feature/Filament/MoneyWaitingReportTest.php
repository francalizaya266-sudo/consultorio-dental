<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Pages\MoneyWaitingReport;
use App\Filament\Widgets\Reports\MoneyWaitingLists;
use App\Filament\Widgets\Reports\MoneyWaitingStats;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MoneyWaitingReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Doctor $torres;

    private Doctor $ramos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'America/Lima'));
        $this->torres = Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        $this->ramos = Doctor::factory()->create(['name' => 'Dr. Luis Ramos']);

        // Presentado hace más de un mes: ya venció y sigue sin respuesta.
        $this->plan('Rosa', 'Quispe', $this->torres, 'Corona', 800, '2026-10-12 11:00');
        // Presentado este mes, sin respuesta todavía.
        $this->plan('Luis', 'Mamani', $this->ramos, 'Endodoncia', 650, '2026-11-10 16:00');
        // Presentado y aceptado este mes.
        $this->plan('Ana', 'Flores', $this->torres, 'Implante', 2500, '2026-11-03 10:00', acceptedAt: '2026-11-05 10:00');

        // Pedro faltó a su limpieza y no volvió a agendar.
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        Appointment::factory()->for($this->torres)->for($cleaning)->for($this->patient('Pedro', 'Huamán'))->at('2026-11-09 09:00')->create(['status' => AppointmentStatus::NoShow]);
        // Carmen faltó a una de las dos sesiones de su endodoncia, pero ya tiene otra cita.
        $carmen = $this->patient('Carmen', 'Rojas');
        $session = $this->plan('Carmen', 'Rojas', $this->ramos, 'Endodoncia', 600, '2026-10-01 10:00', acceptedAt: '2026-10-01 10:00', patient: $carmen)->items->sole();
        $session->update(['sessions_planned' => 2]);
        $rootCanal = Treatment::factory()->create(['name' => 'Endodoncia']);
        Appointment::factory()->for($this->ramos)->for($rootCanal)->for($carmen)->at('2026-11-04 15:00')->create(['status' => AppointmentStatus::NoShow, 'treatment_plan_item_id' => $session->id]);
        Appointment::factory()->for($this->ramos)->for($rootCanal)->for($carmen)->at('2026-11-20 15:00')->confirmed()->create();

        // Elena se fue debiendo.
        Charge::factory()->for($this->patient('Elena', 'Vargas'))->create(['amount' => 150, 'charged_at' => '2026-11-12 10:00']);
    }

    public function test_the_admin_sees_who_did_not_answer_who_missed_what_it_cost_and_how_many_budgets_are_accepted(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get(MoneyWaitingReport::getUrl())->assertOk()->assertSee('Dinero en espera');

        Livewire::test(MoneyWaitingStats::class, ['pageFilters' => ['period' => 'month']])
            ->assertSeeInOrder(['Presupuestos sin respuesta', 'S/ 1,450.00', '2 presupuestos · el más antiguo, hace 35 días'])
            ->assertSeeInOrder(['Presupuestos aceptados', '50 %', '1 de 2 presentados · S/ 2,500.00 de S/ 3,150.00'])
            ->assertSeeInOrder(['Saldos por cobrar', 'S/ 150.00', 'Entre 1 paciente'])
            ->assertSeeInOrder(['Perdido por faltas', 'S/ 420.00', '2 faltas · 1 volvió a agendar']);

        Livewire::test(MoneyWaitingLists::class, ['pageFilters' => ['period' => 'month']])
            // El de más plata primero; el que ya venció, marcado.
            ->assertSeeInOrder(['Rosa Quispe', 'Dra. Ana Torres', 'Hace 35 días', 'Ya venció', 'S/ 800.00', 'Luis Mamani', 'Hace 6 días', 'S/ 650.00', 'S/ 1,450.00'])
            ->assertSeeInOrder(['Faltas del período', 'Pedro Huamán', 'Limpieza dental', 'Sin cita: agendarle', 'S/ 120.00', 'Carmen Rojas', 'Endodoncia', 'Volvió a agendar', 'S/ 300.00'])
            ->assertSeeInOrder(['Los que más deben', 'Elena Vargas', 'S/ 150.00'])
            ->assertSee('Ninguna cuota atrasada.');
    }

    public function test_the_five_numbers_fill_two_rows_and_the_four_of_one_doctor_fill_one(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Tres arriba (2 + 2 + 2 de 6 columnas) y dos abajo (3 + 3): ningún hueco al final.
        $everyone = Livewire::test(MoneyWaitingStats::class, ['pageFilters' => ['period' => 'month']])
            ->assertSee('Saldos por cobrar');
        $this->assertSame(3, substr_count($everyone->html(), '--col-span-lg: span 2 / span 2'));
        $this->assertSame(2, substr_count($everyone->html(), '--col-span-lg: span 3 / span 3'));

        // Con un doctor no hay saldos: quedan cuatro, del mismo ancho.
        $oneDoctor = Livewire::test(MoneyWaitingStats::class, ['pageFilters' => ['period' => 'month', 'doctor' => $this->ramos->id]])
            ->assertDontSee('Saldos por cobrar');
        $this->assertStringNotContainsString('--col-span-lg: span', $oneDoctor->html());
        $this->assertStringContainsString('--cols-xl: repeat(4,', $oneDoctor->html());
    }

    public function test_by_doctor_it_shows_only_their_budgets_and_no_shows_and_leaves_balances_out(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(MoneyWaitingLists::class, ['pageFilters' => ['period' => 'month', 'doctor' => $this->ramos->id]])
            ->assertSee(['Luis Mamani', 'Carmen Rojas'])
            ->assertDontSee(['Rosa Quispe', 'Pedro Huamán', 'Los que más deben']);
    }

    public function test_reception_does_not_see_it(): void
    {
        $this->actingAs(User::factory()->reception()->create());

        $this->get(MoneyWaitingReport::getUrl())->assertForbidden();
    }

    private function patient(string $firstName, string $lastName): Patient
    {
        return Patient::query()->where('first_name', $firstName)->where('last_name', $lastName)->first()
            ?? Patient::factory()->create(['first_name' => $firstName, 'last_name' => $lastName]);
    }

    private function plan(string $firstName, string $lastName, Doctor $doctor, string $procedure, int $price, string $proposedAt, ?string $acceptedAt = null, ?Patient $patient = null): TreatmentPlan
    {
        $plan = TreatmentPlan::create([
            'patient_id' => ($patient ?? $this->patient($firstName, $lastName))->id,
            'doctor_id' => $doctor->id,
            'status' => $acceptedAt ? TreatmentPlanStatus::Accepted : TreatmentPlanStatus::Proposed,
            'proposed_at' => $proposedAt,
            'accepted_at' => $acceptedAt,
        ]);
        $plan->items()->create(['description' => $procedure, 'unit_price' => $price, 'accepted_at' => $acceptedAt]);

        return $plan->load('items');
    }
}
