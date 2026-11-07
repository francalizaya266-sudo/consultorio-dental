<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\Carbon;
use Database\Factories\DailyClosingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El cierre de caja de un día: el fondo con que se abrió, el efectivo que debía haber, el que se contó y lo
 * que quedó por cobrar. Con el día cerrado, sus pagos ya no se anulan ni se registran sin la administradora.
 */
#[Fillable(['date', 'opening_cash', 'expected_cash', 'counted_cash', 'collected', 'pending', 'notes', 'closed_by_user_id'])]
class DailyClosing extends Model
{
    /** @use HasFactory<DailyClosingFactory> */
    use Auditable, HasFactory;

    /** Lo que se le dice a quien quiere cambiar un día cerrado. */
    public const LOCKED = 'La caja de ese día ya se cerró. Pide a la administradora que lo reabra.';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'collected' => 'decimal:2',
            'pending' => 'decimal:2',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** Lo contado menos lo que debía haber: negativo si falta efectivo, positivo si sobra. */
    public function difference(): float
    {
        return round((float) $this->counted_cash - (float) $this->expected_cash, 2);
    }

    /** Si ese día ya se cerró. */
    public static function isClosed(mixed $day): bool
    {
        return static::query()->whereDate('date', Carbon::parse($day)->toDateString())->exists();
    }

    /** Si el día está cerrado para esta persona: la administradora sí puede corregirlo. */
    public static function locksFor(User $user, mixed $day): bool
    {
        return ! $user->isAdmin() && static::isClosed($day);
    }
}
