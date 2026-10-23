<?php

namespace App\Models;

use Database\Factories\TreatmentPackageItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo que incluye un paquete: un tratamiento, cuántas veces y en cuántas sesiones. */
#[Fillable(['treatment_package_id', 'treatment_id', 'description', 'quantity', 'sessions_planned', 'sort_order'])]
class TreatmentPackageItem extends Model
{
    /** @use HasFactory<TreatmentPackageItemFactory> */
    use HasFactory;

    public function package(): BelongsTo
    {
        return $this->belongsTo(TreatmentPackage::class, 'treatment_package_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class)->withTrashed();
    }

    /** "Control de ortodoncia × 18". */
    public function label(): string
    {
        return ($this->description ?: $this->treatment->name).($this->quantity > 1 ? ' × '.$this->quantity : '');
    }

    /** Precio de catálogo de lo incluido. */
    public function normalPrice(): float
    {
        return round($this->quantity * (float) $this->treatment->price_from, 2);
    }
}
