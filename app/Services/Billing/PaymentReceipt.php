<?php

namespace App\Services\Billing;

use App\Enums\MessageTemplateKey;
use App\Models\Payment;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La constancia de un pago: qué pagó (los cargos que cubrió, del más antiguo al más nuevo, como se
 * aplica el saldo), cuánto le quedó por pagar y el mensaje para enviársela por WhatsApp. No es una
 * boleta ni una factura: es el comprobante interno del consultorio.
 */
final class PaymentReceipt
{
    public function __construct(private readonly WhatsAppOutbox $outbox) {}

    /** "N.º 000123". */
    public static function number(Payment $payment): string
    {
        return 'N.º '.str_pad((string) $payment->getKey(), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Lo que cubrió el pago: los cargos que todavía se debían al pagar, en el orden en que se cubren.
     * Lo que sobra queda a cuenta. Una devolución es una sola línea.
     *
     * @return list<array{description: string, amount: float}>
     */
    public function lines(Payment $payment): array
    {
        $amount = round((float) $payment->amount, 2);

        if ($amount < 0) {
            return [['description' => 'Devolución'.(filled($payment->notes) ? ': '.$payment->notes : ''), 'amount' => $amount]];
        }

        $paidBefore = (float) $this->paymentsUpTo($payment)->where('payments.id', '!=', $payment->getKey())->sum('amount');
        $lines = [];
        $left = $amount;

        foreach ($payment->patient->charges()->valid()->orderBy('charged_at')->orderBy('id')->get() as $charge) {
            $net = max(0, $charge->net());
            $coveredBefore = min($paidBefore, $net);
            $paidBefore = round($paidBefore - $coveredBefore, 2);
            $covers = round(min($left, $net - $coveredBefore), 2);

            if ($covers > 0) {
                $lines[] = ['description' => $charge->description, 'amount' => $covers];
                $left = round($left - $covers, 2);
            }

            if ($left <= 0) {
                break;
            }
        }

        if ($left > 0) {
            $lines[] = ['description' => filled($payment->notes) ? 'A cuenta: '.$payment->notes : 'A cuenta', 'amount' => $left];
        }

        return $lines;
    }

    /** Lo que el paciente quedó debiendo (o, en negativo, a favor) justo después de este pago. */
    public function balanceAfter(Payment $payment): float
    {
        $charged = (float) $payment->patient->charges()->valid()->where('charged_at', '<=', $payment->paid_at)->sum(DB::raw('amount - discount'));

        return round($charged - (float) $this->paymentsUpTo($payment)->sum('amount'), 2);
    }

    /** El mensaje con la constancia para el celular del paciente (o del titular). Null si no tiene celular. */
    public function whatsappDraft(Payment $payment): ?WhatsappMessage
    {
        $payment->loadMissing('patient.account');
        $account = $payment->patient->account;

        if (! $account) {
            return null;
        }

        $detail = collect($this->lines($payment))
            ->map(fn (array $line) => '• '.$line['description'].': '.Money::format(abs($line['amount'])))
            ->implode("\n");

        return $this->outbox->draftForAccount($account, MessageTemplateKey::PaymentReceipt, [
            'nombre' => $payment->patient->first_name,
            'paciente' => $payment->patient->full_name,
            'monto' => Money::format(abs((float) $payment->amount)),
            'medio' => mb_strtolower($payment->method->getLabel()),
            'fecha' => $payment->paid_at->format('d/m/Y'),
            'numero' => self::number($payment),
            'detalle' => $detail,
            'saldo' => Money::balance($this->balanceAfter($payment)),
        ], $payment->patient_id);
    }

    /**
     * Los pagos válidos del paciente hasta este, incluido (por fecha y, en la misma fecha, por orden de registro).
     *
     * @return Builder<Payment>
     */
    private function paymentsUpTo(Payment $payment): Builder
    {
        return Payment::query()
            ->where('patient_id', $payment->patient_id)
            ->whereNull('voided_at')
            ->where(fn ($query) => $query
                ->where('paid_at', '<', $payment->paid_at)
                ->orWhere(fn ($query) => $query->where('paid_at', $payment->paid_at)->where('id', '<=', $payment->getKey())));
    }
}
