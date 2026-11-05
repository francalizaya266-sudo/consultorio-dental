<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Phone;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A quién se le compran los materiales. Los pedidos le llegan por WhatsApp. */
#[Fillable(['name', 'contact_name', 'phone', 'email', 'notes', 'is_active'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use Auditable, HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasWhatsApp(): bool
    {
        return filled($this->phone) && Phone::isValid($this->phone);
    }
}
