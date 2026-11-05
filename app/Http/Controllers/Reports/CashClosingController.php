<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\CashClosing;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** La hoja del cierre de caja del día, para contar el efectivo, firmarla y guardarla. */
class CashClosingController extends Controller
{
    public function print(Request $request): View
    {
        abort_unless($request->user()?->can('viewAny', Payment::class), 403);

        return view('reports.cash-closing-print', [
            'closing' => CashClosing::fromFilters($request->only(['date', 'person'])),
        ]);
    }
}
