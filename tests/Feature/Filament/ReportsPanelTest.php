<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Earnings;
use App\Filament\Pages\Settings;
use App\Filament\Pages\Summary;
use App\Filament\Widgets\Reports\BusiestHoursChart;
use App\Filament\Widgets\Reports\EarningsChart;
use App\Filament\Widgets\Reports\EarningsStats;
use App\Filament\Widgets\Reports\EarningsTables;
use App\Filament\Widgets\Reports\IncomeByMonthChart;
use App\Filament\Widgets\Reports\IncomeByTreatmentChart;
use App\Filament\Widgets\Reports\NewVsReturningChart;
use App\Filament\Widgets\Reports\NoShowsChart;
use App\Filament\Widgets\Reports\OccupancyChart;
use App\Filament\Widgets\Reports\OriginsChart;
use App\Filament\Widgets\Reports\SummaryStats;
use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class ReportsPanelTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Doctor $torres;

    private Doctor $ramos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Lima'));
        $this->torres = Doctor::factory()->withSchedule()->create(['name' => 'Dra. Ana Torres']);
        $this->ramos = Doctor::factory()->withSchedule()->create(['name' => 'Dr. Luis Ramos']);
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $patient = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe', 'origin' => AppointmentOrigin::Google]);

        foreach ([[$this->torres, 120, PaymentMethod::Cash, '2026-10-02 10:00'], [$this->ramos, 450, PaymentMethod::Yape, '2026-10-05 16:00']] as [$doctor, $amount, $method, $at]) {
            Appointment::factory()->for($doctor)->for($cleaning)->for($patient)->at($at)->create(['status' => AppointmentStatus::Completed]);
            Charge::factory()->for($patient)->create(['doctor_id' => $doctor->id, 'treatment_id' => $cleaning->id, 'amount' => $amount, 'charged_at' => $at]);
            Payment::factory()->for($patient)->create(['doctor_id' => $doctor->id, 'treatment_id' => $cleaning->id, 'amount' => $amount, 'method' => $method, 'paid_at' => $at]);
        }
    }

    private function url(string $route, array $query = []): string
    {
        return Filament::getPanel('admin')->route('reports.earnings.'.$route, $query);
    }

    public function test_the_admin_sees_earnings_and_the_summary_charts(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $filters = ['pageFilters' => ['period' => 'month']];

        $this->get(Summary::getUrl())->assertOk()->assertSee('Resumen');
        $this->get(Earnings::getUrl())->assertOk()->assertSee('Descargar Excel')->assertSee('Imprimir o PDF');

        Livewire::test(EarningsStats::class, $filters)->assertSee(['Cobrado', 'S/ 570.00', 'S/ 0.00 en 1–23 sep.', 'Pagos', 'Promedio S/ 285.00', 'Por cobrar (a hoy)']);
        Livewire::test(EarningsChart::class, $filters)->assertSee('El mejor día: Lun. 5 oct. (S/ 450.00)');
        Livewire::test(EarningsTables::class, $filters)->assertSee(['Por día', 'Por doctor', 'Por tratamiento', 'Por medio de pago', 'Dr. Luis Ramos', 'Yape', '78.9 %']);
        Livewire::test(SummaryStats::class, $filters)->assertSee(['Citas atendidas', 'Pacientes nuevos', 'Ingresos (cobrado)', 'Inasistencias', 'Sin citas en 1–23 sep.']);
        Livewire::test(IncomeByMonthChart::class, $filters)->assertSee('Este mes: S/ 570.00');
        Livewire::test(IncomeByTreatmentChart::class, $filters)->assertSee('Lo que más dejó: Limpieza dental (S/ 570.00)');
        Livewire::test(BusiestHoursChart::class, $filters)->assertSee('La hora más pedida');
        Livewire::test(NewVsReturningChart::class, $filters)->assertSee('Este mes: 1 nuevo y 0 recurrentes');
        Livewire::test(OriginsChart::class, $filters)->assertSee('Llegan más por Google: 100 % de las citas');
        // Lunes 5: el Dr. Ramos tuvo 30 minutos reservados de 8 horas; la Dra. Torres, nada.
        Livewire::test(OccupancyChart::class, ['pageFilters' => ['period' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-05']])
            ->assertSee('En promedio, 3 % del horario reservado');
        Livewire::test(NoShowsChart::class, $filters)->assertSee('Este mes: 0 %');
    }

    public function test_charts_without_data_say_so(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EarningsChart::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('Aún no hay datos para este período')
            ->assertDontSee('El mejor día');
    }

    public function test_the_admin_can_filter_by_doctor(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EarningsStats::class, ['pageFilters' => ['period' => 'month', 'doctor' => $this->torres->id]])
            ->assertSee('S/ 120.00')
            ->assertDontSee('S/ 570.00')
            // Lo que deben los pacientes no es de un doctor.
            ->assertDontSee('Por cobrar (a hoy)');
    }

    public function test_reception_does_not_see_earnings(): void
    {
        $this->actingAs(User::factory()->reception()->create());

        foreach ([Summary::getUrl(), Earnings::getUrl(), $this->url('print'), $this->url('excel')] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->assertFalse(EarningsStats::canView());
        // "Hoy" sigue igual, sin los gráficos de reportes.
        $this->assertSame([TodayStats::class, TodayAppointments::class], (new Dashboard)->getWidgets());
    }

    public function test_a_doctor_sees_only_their_own_numbers_once_the_clinic_allows_it(): void
    {
        $this->actingAs($this->torres->user);

        $this->get(Earnings::getUrl())->assertForbidden();
        $this->get($this->url('excel'))->assertForbidden();

        Setting::set('reports.doctors_see_own', true);

        $this->get(Summary::getUrl())->assertOk();
        $this->get(Earnings::getUrl())->assertOk();

        // Aunque pida los de otro doctor, ve los suyos.
        Livewire::test(EarningsStats::class, ['pageFilters' => ['period' => 'month', 'doctor' => $this->ramos->id]])
            ->assertSee('S/ 120.00')
            ->assertDontSee('S/ 450.00')
            ->assertDontSee('Por cobrar (a hoy)');

        $this->get($this->url('print', ['doctor' => $this->ramos->id]))
            ->assertOk()
            ->assertSee('Dra. Ana Torres')
            ->assertSee('S/ 120.00')
            ->assertDontSee('S/ 450.00');
    }

    public function test_the_earnings_print_out_shows_the_period_totals_and_tables(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get($this->url('print', ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-10']))
            ->assertOk()
            ->assertSeeInOrder(['Ganancias', 'Del 1 al 10 de octubre de 2026', 'Cobrado', 'S/ 570.00', 'Por cobrar (a hoy)'])
            ->assertSeeInOrder(['Por día', 'Vie. 2 oct.', 'Lun. 5 oct.', 'Por doctor', 'Por tratamiento', 'Por medio de pago']);
    }

    public function test_the_excel_download_has_a_sheet_per_table_and_every_payment(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get($this->url('excel', ['period' => 'month']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="ganancias_2026-10-01_2026-10-23.xlsx"');

        $path = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        $workbook = $zip->getFromName('xl/workbook.xml');
        foreach (['Resumen', 'Por día', 'Por doctor', 'Por tratamiento', 'Por medio de pago', 'Pagos'] as $sheet) {
            $this->assertStringContainsString('name="'.$sheet.'"', $workbook);
        }

        $payments = $zip->getFromName('xl/worksheets/sheet6.xml');
        $this->assertStringContainsString('Rosa Quispe', $payments);
        $this->assertStringContainsString('<v>450</v>', $payments);
        // Las fechas van como fecha de Excel: 2 de octubre de 2026.
        $this->assertStringContainsString('<v>46297</v>', $payments);

        $zip->close();
        unlink($path);
    }

    public function test_the_admin_turns_on_reports_for_doctors_in_settings(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->assertSee('Cada doctor ve sus propias ganancias y gráficos')
            ->fillForm(['reports' => ['doctors_see_own' => true]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Setting::get('reports.doctors_see_own'));
    }
}
