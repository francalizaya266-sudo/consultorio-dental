<?php

namespace Tests\Feature\Services\Reports;

use App\Enums\PaymentMethod;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Services\Reports\EarningsReport;
use App\Services\Reports\EarningsTables;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EarningsReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Doctor $torres;

    private Doctor $ramos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Lima'));
        $this->torres = Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        $this->ramos = Doctor::factory()->create(['name' => 'Dr. Luis Ramos']);
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $patient = Patient::factory()->create();

        // Octubre: dos pagos, una limpieza con descuento y un trabajo de laboratorio sin tratamiento del catálogo.
        Payment::factory()->for($patient)->create(['doctor_id' => $this->torres->id, 'amount' => 100, 'method' => PaymentMethod::Cash, 'paid_at' => '2026-10-02 10:30']);
        Payment::factory()->for($patient)->create(['doctor_id' => $this->ramos->id, 'amount' => 50, 'method' => PaymentMethod::Yape, 'paid_at' => '2026-10-02 17:00']);
        Charge::factory()->for($patient)->create(['doctor_id' => $this->torres->id, 'treatment_id' => $cleaning->id, 'amount' => 120, 'discount' => 20, 'charged_at' => '2026-10-02 10:20']);
        Charge::factory()->for($patient)->create(['doctor_id' => $this->ramos->id, 'description' => 'Corona de laboratorio', 'amount' => 300, 'charged_at' => '2026-10-05 16:00']);

        // Lo anulado y lo de otro mes no cuenta.
        Payment::factory()->for($patient)->create(['doctor_id' => $this->torres->id, 'amount' => 999, 'paid_at' => '2026-10-03 10:00', 'voided_at' => now(), 'void_reason' => 'Error']);
        Charge::factory()->for($patient)->create(['doctor_id' => $this->torres->id, 'amount' => 999, 'charged_at' => '2026-10-03 10:00', 'voided_at' => now(), 'void_reason' => 'Error']);
        Payment::factory()->for($patient)->create(['doctor_id' => $this->torres->id, 'amount' => 70, 'paid_at' => '2026-09-30 18:00']);
    }

    private function report(?Doctor $doctor = null, string $period = 'month'): EarningsReport
    {
        return new EarningsReport(ReportPeriod::fromFilters(['period' => $period]), $doctor?->id);
    }

    public function test_it_adds_up_what_was_collected_and_what_was_done_without_voided_records(): void
    {
        $report = $this->report();

        $this->assertSame([150.0, 2, 400.0], [$report->collected(), $report->paymentsCount(), $report->produced()]);
    }

    public function test_it_breaks_earnings_down_by_day_doctor_treatment_and_payment_method(): void
    {
        $report = $this->report();

        $this->assertSame([
            ['Vie. 2 oct.', 150.0, 100.0, 2],
            ['Lun. 5 oct.', 0.0, 300.0, 0],
        ], array_map(fn (array $row) => [$row['label'], $row['collected'], $row['produced'], $row['payments']], $report->byPeriod()));
        $this->assertCount(23, $report->byPeriod(withEmpty: true));

        $this->assertSame([
            ['name' => 'Dr. Luis Ramos', 'produced' => 300.0, 'collected' => 50.0],
            ['name' => 'Dra. Ana Torres', 'produced' => 100.0, 'collected' => 100.0],
        ], $report->byDoctor());

        // Lo que no es del catálogo va al final, aunque sume más.
        $this->assertSame([
            ['name' => 'Limpieza dental', 'produced' => 100.0, 'count' => 1],
            ['name' => EarningsReport::OTHER_CONCEPTS, 'produced' => 300.0, 'count' => 1],
        ], $report->byTreatment());

        $this->assertSame([
            ['Efectivo', 100.0, 1, 66.7],
            ['Yape', 50.0, 1, 33.3],
        ], array_map(fn (array $row) => [$row['label'], $row['collected'], $row['payments'], $row['share']], $report->byMethod()));
    }

    public function test_with_a_doctor_it_only_counts_that_doctors_work(): void
    {
        $report = $this->report($this->torres);

        $this->assertSame([100.0, 1, 100.0], [$report->collected(), $report->paymentsCount(), $report->produced()]);
        $this->assertSame(['Dra. Ana Torres'], array_column($report->byDoctor(), 'name'));
        $this->assertSame(['Limpieza dental'], array_column($report->byTreatment(), 'name'));
    }

    public function test_long_periods_are_grouped_by_month(): void
    {
        $rows = $this->report(period: 'year')->byPeriod();

        $this->assertSame([['Septiembre 2026', 70.0], ['Octubre 2026', 150.0]], array_map(fn (array $row) => [$row['label'], $row['collected']], $rows));
    }

    public function test_outstanding_only_adds_what_patients_owe(): void
    {
        // El paciente de arriba: 400 en cargos, 220 pagados.
        $inCredit = Patient::factory()->create();
        Charge::factory()->for($inCredit)->create(['amount' => 50]);
        Payment::factory()->for($inCredit)->create(['amount' => 100]);

        $this->assertSame(180.0, EarningsReport::outstanding());
    }

    public function test_the_tables_show_the_same_numbers_with_totals(): void
    {
        $tables = collect(EarningsTables::for($this->report()))->keyBy('key');

        $this->assertSame(['period', 'doctor', 'treatment', 'method'], $tables->keys()->all());
        $this->assertSame(['Total', 150.0, 400.0, 2], $tables['period']['total']);
        $this->assertSame(['Total', 2, 150.0, 100.0], $tables['method']['total']);
        $this->assertSame('S/ 1,250.50', EarningsTables::format(1250.5, EarningsTables::MONEY));
        $this->assertSame('66.7 %', EarningsTables::format(66.7, EarningsTables::PERCENT));

        // Con un doctor elegido no hay tabla por doctor; sin pagos, la tabla queda sin total.
        $quiet = collect(EarningsTables::for(new EarningsReport(ReportPeriod::fromFilters(['period' => 'today']), $this->ramos->id)))->keyBy('key');
        $this->assertSame(['period', 'treatment', 'method'], $quiet->keys()->all());
        $this->assertSame([[], null], [$quiet['method']['rows'], $quiet['method']['total']]);
    }
}
