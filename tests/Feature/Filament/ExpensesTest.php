<?php

namespace Tests\Feature\Filament;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Filament\Widgets\CashClosingCheck;
use App\Filament\Widgets\CashClosingStats;
use App\Filament\Widgets\CashClosingTables;
use App\Filament\Widgets\Reports\EarningsTables;
use App\Models\DailyClosing;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Supply;
use App\Models\User;
use App\Services\Billing\CashClosing;
use App\Services\Reports\EarningsReport;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpensesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-11-02 19:30'));
        $this->reception = User::factory()->reception()->create(['name' => 'Carla Ríos']);
        Payment::factory()->for(Patient::factory())->create(['amount' => 200, 'method' => PaymentMethod::Cash, 'paid_at' => '2026-11-02 10:00', 'received_by_user_id' => $this->reception->id]);
    }

    public function test_reception_notes_an_expense_and_the_cash_paid_leaves_the_drawer(): void
    {
        $this->actingAs($this->reception);

        Livewire::test(ManageExpenses::class)
            ->callAction('create', ['description' => 'Corona de Rosa Quispe', 'category' => ExpenseCategory::Laboratory->value, 'amount' => 30, 'method' => PaymentMethod::Cash->value, 'spent_at' => '2026-11-02 12:00'])
            ->assertHasNoActionErrors()
            ->assertNotified('Gasto anotado')
            ->assertSee(['Corona de Rosa Quispe', 'Laboratorio dental', '30.00']);

        $expense = Expense::sole();
        $this->assertSame([$this->reception->id, ExpenseCategory::Laboratory, 30.0], [$expense->paid_by_user_id, $expense->category, (float) $expense->amount]);
        // Lo pagado por Yape no toca el efectivo.
        Expense::factory()->create(['description' => 'Recibo de luz', 'category' => ExpenseCategory::Utilities, 'amount' => 50, 'method' => PaymentMethod::Yape, 'spent_at' => '2026-11-02 13:00']);

        $closing = new CashClosing(CarbonImmutable::parse('2026-11-02'));
        $this->assertSame([200.0, 30.0, 170.0], [$closing->cash(), $closing->cashExpenses(), $closing->cashInDrawer()]);

        $filters = ['pageFilters' => ['date' => '2026-11-02']];
        Livewire::test(CashClosingStats::class, $filters)->assertSeeInOrder(['Efectivo en caja', 'S/ 170.00', 'S/ 200.00 cobrados menos S/ 30.00 de gastos']);
        Livewire::test(CashClosingTables::class, $filters)->assertSeeInOrder(['Gastos del día', '12:00', 'Corona de Rosa Quispe', 'Efectivo', 'Carla Ríos', 'S/ 30.00', 'Recibo de luz', 'S/ 80.00']);

        // El arqueo espera el fondo más lo cobrado menos lo gastado en efectivo.
        Livewire::test(CashClosingCheck::class, $filters)
            ->mountAction('closeDay')
            ->setActionData(['opening_cash' => 100, 'counted_cash' => 270])
            ->assertMountedActionModalSee('Debe haber S/ 270.00: el fondo más S/ 200.00 cobrados en efectivo, menos S/ 30.00 de gastos pagados en efectivo.')
            ->callMountedAction()
            ->assertHasNoErrors();
        $this->assertSame([270.0, 0.0], [(float) DailyClosing::sole()->expected_cash, DailyClosing::sole()->difference()]);

        // Con el día cerrado, recepción ya no anula ese gasto ni anota otro con esa fecha.
        Livewire::test(ManageExpenses::class)
            ->assertActionDisabled(TestAction::make('void')->table($expense))
            ->callAction('create', ['description' => 'Movilidad', 'amount' => 10, 'spent_at' => '2026-11-02 18:00'])
            ->assertHasActionErrors(['spent_at']);

        // La administradora sí lo anula, y deja de restar.
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ManageExpenses::class)
            ->callAction(TestAction::make('void')->table($expense), ['reason' => 'Se anotó dos veces'])
            ->assertNotified('Gasto anulado');
        $this->assertSame(200.0, (new CashClosing(CarbonImmutable::parse('2026-11-02')))->cashInDrawer());

        // El doctor no entra a los gastos.
        $this->actingAs(Doctor::factory()->create()->user);
        $this->get(ExpenseResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_earnings_report_says_what_is_left_after_expenses_and_materials(): void
    {
        Expense::factory()->create(['description' => 'Corona', 'category' => ExpenseCategory::Laboratory, 'amount' => 60, 'spent_at' => '2026-11-02 12:00']);
        Expense::factory()->create(['description' => 'Alquiler', 'category' => ExpenseCategory::Rent, 'amount' => 20, 'method' => PaymentMethod::Transfer, 'spent_at' => '2026-11-01 09:00']);
        Expense::factory()->create(['description' => 'Mal anotado', 'amount' => 500, 'spent_at' => '2026-11-02 12:00', 'voided_at' => now()]);
        Expense::factory()->create(['description' => 'Del mes pasado', 'amount' => 90, 'spent_at' => '2026-10-15 12:00']);
        $gloves = Supply::factory()->create();
        StockMovement::create(['supply_id' => $gloves->id, 'type' => StockMovementType::Use, 'quantity' => -2, 'unit_cost' => 5]);
        StockMovement::create(['supply_id' => $gloves->id, 'type' => StockMovementType::Receipt, 'quantity' => 100, 'unit_cost' => 5]);

        $report = new EarningsReport(new ReportPeriod(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')->endOfDay()));
        $this->assertSame([200.0, 80.0, 10.0, 110.0], [$report->collected(), $report->spent(), $report->materialsCost(), $report->profit()]);

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EarningsTables::class, ['pageFilters' => ['period' => 'month']])
            ->assertSeeInOrder(['Lo que queda', 'Cobrado', 'S/ 200.00', 'Menos gastos anotados en Caja', 'S/ 80.00', 'Menos materiales usados', 'S/ 10.00', 'Queda', 'S/ 110.00'])
            ->assertSeeInOrder(['Gastos por tipo', 'Laboratorio dental', 'S/ 60.00', 'Alquiler', 'S/ 20.00'])
            ->assertDontSee('Otros gastos');

        // Con un doctor elegido no se calcula: los gastos son del consultorio.
        Livewire::test(EarningsTables::class, ['pageFilters' => ['period' => 'month', 'doctor' => Doctor::factory()->create()->id]])
            ->assertDontSee(['Lo que queda', 'Gastos por tipo']);
    }
}
