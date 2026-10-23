<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Reports\EarningsReport;
use App\Services\Reports\EarningsTables;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportPeriod;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Las ganancias del período elegido en el panel, para imprimir (o guardar en PDF) y para Excel. */
class EarningsExportController extends Controller
{
    public function print(Request $request): View
    {
        $report = $this->report($request);

        return view('reports.earnings-print', [
            'report' => $report,
            'doctor' => $report->doctorId ? Doctor::find($report->doctorId) : null,
            'tables' => EarningsTables::for($report),
            'outstanding' => $report->doctorId ? null : EarningsReport::outstanding(),
        ]);
    }

    public function excel(Request $request): Response
    {
        $report = $this->report($request);
        $book = new Spreadsheet;
        $doctor = $report->doctorId ? Doctor::find($report->doctorId)?->name : null;
        $collected = $report->collected();
        $count = $report->paymentsCount();

        $book->sheet('Resumen', ['Ganancias', ''], [Spreadsheet::TEXT, Spreadsheet::MONEY], array_values(array_filter([
            ['Consultorio', Setting::get('clinic.name')],
            ['Período', $report->period->label()],
            ['Doctor', $doctor ?? 'Todos'],
            ['Cobrado', $collected],
            ['Realizado', $report->produced()],
            ['Pago promedio', $count > 0 ? round($collected / $count, 2) : 0],
            $report->doctorId ? null : ['Por cobrar (a hoy)', EarningsReport::outstanding()],
        ])));

        foreach (EarningsTables::for($report) as $table) {
            $book->sheet(
                $table['title'],
                array_column($table['columns'], 'label'),
                array_map(fn (array $column) => self::cellType($column['type']), $table['columns']),
                $table['rows'],
                $table['total'],
            );
        }

        $payments = $report->payments()->with(['patient', 'doctor', 'treatment', 'receivedBy'])->orderBy('paid_at')->get();
        $book->sheet(
            'Pagos',
            ['Fecha', 'Hora', 'Paciente', 'Doctor', 'Tratamiento', 'Medio de pago', 'N.º de operación', 'Monto', 'Recibió', 'Nota'],
            [Spreadsheet::DATE, Spreadsheet::TEXT, Spreadsheet::TEXT, Spreadsheet::TEXT, Spreadsheet::TEXT, Spreadsheet::TEXT, Spreadsheet::TEXT, Spreadsheet::MONEY, Spreadsheet::TEXT, Spreadsheet::TEXT],
            $payments->map(fn (Payment $p) => [
                $p->paid_at,
                $p->paid_at->format('H:i'),
                $p->patient?->full_name,
                $p->doctor?->name,
                $p->treatment?->name,
                $p->method->getLabel(),
                $p->reference,
                (float) $p->amount,
                $p->receivedBy?->name,
                $p->notes,
            ])->all(),
            $payments->isEmpty() ? null : ['Total', null, null, null, null, null, null, $collected],
        );

        $name = 'ganancias_'.$report->period->slug();

        if (Spreadsheet::canWriteXlsx()) {
            return response($book->toXlsx(), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"',
            ]);
        }

        return response($book->toCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'.csv"',
        ]);
    }

    /** El período y el doctor vienen de los filtros del panel; un doctor solo puede pedir lo suyo. */
    private function report(Request $request): EarningsReport
    {
        $user = $request->user();
        abort_unless(ReportAccess::canView($user), 403);

        return new EarningsReport(
            ReportPeriod::fromFilters($request->only(['period', 'from', 'to'])),
            ReportAccess::doctorFor($user, $request->query('doctor')),
        );
    }

    private static function cellType(string $type): string
    {
        return match ($type) {
            EarningsTables::MONEY => Spreadsheet::MONEY,
            EarningsTables::COUNT => Spreadsheet::COUNT,
            EarningsTables::PERCENT => Spreadsheet::PERCENT,
            default => Spreadsheet::TEXT,
        };
    }
}
