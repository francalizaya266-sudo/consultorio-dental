<?php

namespace Tests\Feature\Filament;

use App\Enums\PaymentMethod;
use App\Filament\Pages\CashClosing;
use App\Filament\Widgets\CashClosingStats;
use App\Filament\Widgets\CashClosingTables;
use App\Filament\Widgets\TodayStats;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Billing\CashClosing as Closing;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class CashClosingPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private User $rosa;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-11-02 19:30'));
        $this->rosa = User::factory()->reception()->create(['name' => 'Rosa Pérez']);
        $this->luis = User::factory()->reception()->create(['name' => 'Luis Soto']);
        $ana = Patient::factory()->create(['first_name' => 'Ana', 'last_name' => 'Flores']);
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);

        $this->pay($ana, 150, PaymentMethod::Cash, '09:10', $this->rosa, $cleaning);
        $this->pay($ana, 80, PaymentMethod::Yape, '10:40', $this->rosa, reference: '0012345');
        $this->pay($ana, 300, PaymentMethod::Card, '16:05', $this->luis);
        $this->pay($ana, 50, PaymentMethod::Cash, '17:30', $this->luis);
        // Anulado: se lista aparte y no suma.
        $this->pay($ana, 999, PaymentMethod::Cash, '11:00', $this->rosa)->update(['voided_at' => now(), 'voided_by_user_id' => $this->luis->id, 'void_reason' => 'Monto mal escrito']);
        // Ayer: no es de este cierre.
        Payment::factory()->for($ana)->create(['amount' => 500, 'method' => PaymentMethod::Cash, 'paid_at' => '2026-11-01 18:00', 'received_by_user_id' => $this->rosa->id]);
    }

    public function test_the_day_adds_up_by_method_and_by_person_without_what_was_voided(): void
    {
        $closing = new Closing(now()->toImmutable()->startOfDay());

        $this->assertSame(580.0, $closing->total());
        $this->assertSame(200.0, $closing->cash());
        $this->assertSame(4, $closing->payments()->count());

        [$methods, $people, $payments, $voided] = $closing->tables();
        $this->assertSame([['Efectivo', 2, 200.0], ['Tarjeta', 1, 300.0], ['Yape', 1, 80.0]], $methods['rows']);
        $this->assertSame([['Luis Soto', 50.0, 300.0, 350.0], ['Rosa Pérez', 150.0, 80.0, 230.0]], $people['rows']);
        $this->assertSame(['09:10', 'Ana Flores', 'Limpieza dental', 'Efectivo', '', 'Rosa Pérez', 150.0], $payments['rows'][0]);
        $this->assertSame([['11:00', 'Ana Flores', 'Efectivo', 999.0, 'Luis Soto', 'Monto mal escrito']], $voided['rows']);
    }

    public function test_one_persons_closing_only_counts_what_they_received(): void
    {
        $closing = Closing::fromFilters(['date' => '2026-11-02', 'person' => $this->rosa->id]);

        $this->assertSame(230.0, $closing->total());
        $this->assertSame(150.0, $closing->cash());
        $this->assertSame(['method', 'payments', 'voided'], array_column($closing->tables(), 'key'));
        $this->assertSame([$this->luis->id => 'Luis Soto', $this->rosa->id => 'Rosa Pérez'], Closing::receivers($closing->date));
    }

    public function test_a_future_or_invalid_day_closes_today(): void
    {
        $this->assertTrue(Closing::fromFilters(['date' => '2026-11-03'])->date->isToday());
        $this->assertTrue(Closing::fromFilters(['date' => 'ayer'])->date->isToday());
        $this->assertSame('2026-11-01', Closing::fromFilters(['date' => '2026-11-01'])->date->toDateString());
    }

    public function test_reception_sees_the_closing_and_prints_it(): void
    {
        $this->actingAs($this->rosa);

        $this->get(CashClosing::getUrl())->assertOk()->assertSee('Cierre del día');

        Livewire::test(CashClosingStats::class, ['pageFilters' => ['date' => '2026-11-02']])
            ->assertSee(['Cobrado', 'S/ 580.00', '4 pagos · 1 anulado aparte', 'Efectivo en caja', 'S/ 200.00', 'Yape y Plin', 'S/ 80.00']);

        Livewire::test(CashClosingTables::class, ['pageFilters' => ['date' => '2026-11-02']])
            ->assertSee(['Por medio de pago', 'Por persona', 'Luis Soto', 'Pagos del día', '0012345', 'Anulados (no suman)', 'Monto mal escrito']);

        $this->get(Filament::getPanel('admin')->route('cash-closing.print', ['date' => '2026-11-02']))
            ->assertOk()
            ->assertSee(['Cierre del día', 'Lunes 2 de noviembre de 2026', 'Arqueo del efectivo', 'Según el sistema', 'Entregó', 'Recibió'])
            ->assertDontSee('S/ 500.00');
    }

    public function test_doctors_do_not_see_the_cash(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $this->actingAs($doctor->user);

        $this->get(CashClosing::getUrl())->assertForbidden();
        $this->get(Filament::getPanel('admin')->route('cash-closing.print'))->assertForbidden();
    }

    public function test_collected_today_leads_to_the_closing_when_nobody_left_owing(): void
    {
        $this->actingAs($this->rosa);

        Livewire::test(TodayStats::class)
            ->assertSee(['Cobrado hoy', 'S/ 580.00'])
            ->assertSeeHtml(CashClosing::getUrl());
    }

    private function pay(Patient $patient, int $amount, PaymentMethod $method, string $time, User $by, ?Treatment $treatment = null, ?string $reference = null): Payment
    {
        return Payment::factory()->for($patient)->create([
            'amount' => $amount,
            'method' => $method,
            'paid_at' => '2026-11-02 '.$time,
            'received_by_user_id' => $by->id,
            'treatment_id' => $treatment?->id,
            'reference' => $reference,
        ]);
    }
}
