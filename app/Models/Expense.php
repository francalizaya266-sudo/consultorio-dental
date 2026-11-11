<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Concerns\Auditable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gasto del consultorio. Si se pagó en efectivo, sale de la caja de ese día. No se borra: se anula. */
#[Fillable(['spent_at', 'category', 'description', 'amount', 'method', 'paid_by_user_id', 'voided_at', 'voided_by_user_id', 'void_reason'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'spent_at' => 'datetime',
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'voided_at' => 'datetime',
        ];
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /** Los que cuentan: sin los anulados. */
    #[Scope]
    protected function valid(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
