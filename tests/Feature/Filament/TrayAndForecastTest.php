<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TomorrowTray;
use App\Filament\Resources\Supplies\Pages\ManageSupplies;
use App\Filament\Widgets\FollowUpsToday;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Supply;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\SupplyForecast;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TrayAndForecastTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private User $reception;

    private Treatment $filling;

    private Supply $anesthesia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeMonday();
        $this->reception = User::factory()->reception()->create();
        $this->filling = Treatment::factory()->create(['name' => 'Curación con resina', 'duration_minutes' => 30]);
        $this->anesthesia = Supply::factory()->create(['name' => 'Lidocaína 2%', 'unit' => 'carpule', 'min_stock' => 4]);
        $this->filling->supplies()->attach($this->anesthesia->id, ['quantity' => 2]);
        app(InventoryService::class)->receive($this->anesthesia, 10, $this->reception, ['lot' => 'L1', 'expires_on' => '2027-03-31']);
    }

    public function test_the_appointments_ahead_say_when_a_supply_runs_out(): void
    {
        $doctor = $this->doctorOffering($this->filling);
        // 2 carpules por cita: con 10 alcanza para 5. El miércoles baja del mínimo (quedan 2) y el jueves se acaba.
        foreach (['2026-11-03 10:00', '2026-11-03 11:00', '2026-11-04 10:00', '2026-11-04 11:00', '2026-11-05 10:00', '2026-11-05 11:00'] as $at) {
            Appointment::factory()->for($doctor)->for($this->filling)->confirmed()->at($at)->create();
        }
        Appointment::factory()->for($doctor)->for($this->filling)->cancelled()->at('2026-11-03 15:00')->create();

        $line = app(SupplyForecast::class)->for($this->anesthesia);
        $this->assertSame(['2026-11-05', '2026-11-04', 12.0], [$line['runs_out_on']->toDateString(), $line['below_min_on']->toDateString(), $line['needed']]);

        $this->actingAs($this->reception);

        Livewire::test(FollowUpsToday::class)
            ->assertSee(['Materiales que se acaban pronto', 'Lidocaína 2% (se acaba ~05/11)', 'Pídelos antes de que falten.']);

        Livewire::test(ManageSupplies::class)
            ->set('activeTab', 'soon')
            ->assertCanSeeTableRecords([$this->anesthesia])
            ->assertSee('Se acaba ~05/11 con las citas agendadas');
    }

    public function test_the_tray_lists_what_each_appointment_uses_with_a_different_implant_each(): void
    {
        $implantSurgery = Treatment::factory()->create(['name' => 'Implante', 'duration_minutes' => 60]);
        $implant = Supply::factory()->serialized()->create(['name' => 'Implante 4.0 x 10']);
        $implantSurgery->supplies()->attach([$implant->id => ['quantity' => 1], $this->anesthesia->id => ['quantity' => 4]]);
        app(InventoryService::class)->receiveSerials($implant, ['SN-2', 'SN-1'], $this->reception, ['expires_on' => '2030-01-31']);
        $doctor = $this->doctorOffering($implantSurgery, $this->filling);

        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $rosa->medicalBackground()->create(['allergies' => 'Látex']);
        Appointment::factory()->for($rosa)->for($doctor)->for($implantSurgery)->confirmed()->at('2026-11-03 09:00')->create();
        Appointment::factory()->for(Patient::factory()->state(['first_name' => 'Luis', 'last_name' => 'Soto']))->for($doctor)->for($implantSurgery)->confirmed()->at('2026-11-03 11:00')->create();
        Appointment::factory()->for(Patient::factory()->state(['first_name' => 'Ana', 'last_name' => 'Flores']))->for($doctor)->for($this->filling)->confirmed()->at('2026-11-03 15:00')->create();

        $this->actingAs($this->reception);

        Livewire::test(TomorrowTray::class)
            ->assertSee('Bandeja de mañana')
            ->assertSeeInOrder([
                '09:00', 'Rosa Quispe', '⚠ Alergia: Látex', 'Implante 4.0 x 10', 'serie SN-2', 'Lidocaína 2%', '4 carpules', 'Lote L1',
                '11:00', 'Luis Soto', 'Implante 4.0 x 10', 'serie SN-1', 'Lidocaína 2%', '4 carpules',
                // A la tercera cita ya no le alcanzan: se separaron 8 de los 10.
                '15:00', 'Ana Flores', 'Lidocaína 2%', '2 carpules', 'Lote L1',
            ])
            ->call('mountAction', 'next')
            ->assertSee('No hay citas ese día.');
    }

    public function test_the_tray_warns_when_the_day_needs_more_than_there_is(): void
    {
        $doctor = $this->doctorOffering($this->filling);
        foreach (['09:00', '10:00', '11:00', '12:00', '15:00', '16:00'] as $time) {
            Appointment::factory()->for($doctor)->for($this->filling)->confirmed()->at("2026-11-03 {$time}")->create();
        }

        $this->actingAs($this->reception);

        // Con 10 carpules alcanzan cinco curaciones: la sexta se entera hoy, no mañana a la hora.
        Livewire::test(TomorrowTray::class)->assertSeeInOrder(['15:00', 'Lote L1', '16:00', 'No queda']);
        Livewire::test(FollowUpsToday::class)->assertSeeInOrder(['Bandeja de mañana', 'No alcanza: Lidocaína 2%.']);
    }
}
