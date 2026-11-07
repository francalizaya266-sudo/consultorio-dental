<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\PaymentReceipt;
use App\Services\WhatsApp\WhatsAppOutbox;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** La constancia de un pago: la hoja para imprimir y el envío por WhatsApp con el texto ya escrito. */
class PaymentReceiptController extends Controller
{
    public function show(Payment $payment, PaymentReceipt $receipts): View
    {
        abort_unless(auth()->user()->can('view', $payment), 403);

        $payment->load('patient.account', 'receivedBy', 'voidedBy');

        return view('print.receipt', [
            'payment' => $payment,
            'patient' => $payment->patient,
            'number' => PaymentReceipt::number($payment),
            'lines' => $receipts->lines($payment),
            'balance' => $payment->voided_at ? null : $receipts->balanceAfter($payment),
            'whatsappUrl' => $payment->voided_at === null && $payment->patient->account
                ? Filament::getPanel('admin')->route('print.receipt.whatsapp', ['payment' => $payment])
                : null,
        ]);
    }

    /** Abre WhatsApp con la constancia escrita; queda registrada en Avisos › Enviados. */
    public function whatsapp(Payment $payment, PaymentReceipt $receipts, WhatsAppOutbox $outbox): RedirectResponse
    {
        abort_unless(auth()->user()->can('view', $payment) && $payment->voided_at === null, 403);

        $message = $receipts->whatsappDraft($payment);
        abort_if($message === null, 404);

        $message->save();

        return redirect()->away($outbox->clickToChatUrl($message, auth()->user()));
    }
}
