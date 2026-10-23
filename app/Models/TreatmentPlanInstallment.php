<?php

namespace App\Models;

use App\Enums\PlanPaymentMode;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un pago del cronograma del plan: la inicial (número 0), el pago único o una cuota. Se carga al vencer. */
#[Fillable(['treatment_plan_id', 'number', 'due_on', 'amount', 'discount', 'charge_id'])]
class TreatmentPlanInstallment extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /** Lo que paga: el monto menos el descuento por pagar todo junto. */
    public function net(): float
    {
        return round((float) $this->amount - (float) $this->discount, 2);
    }

    /** "Pago único", "Inicial" o "Cuota 3 de 18". */
    public function label(): string
    {
        $plan = $this->plan;

        if ($plan->payment_mode === PlanPaymentMode::Upfront) {
            return 'Pago único';
        }

        return $this->number === 0 ? 'Inicial' : 'Cuota '.$this->number.' de '.$plan->installments->where('number', '>', 0)->count();
    }
}
