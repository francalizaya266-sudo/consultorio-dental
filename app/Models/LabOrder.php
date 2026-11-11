<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\LabOrderStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\LabOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Trabajo que se mandó al laboratorio dental (corona, prótesis, férula) para un paciente: cuándo salió, cuándo
 * lo entregan y cuánto cuesta. Avisa si se atrasa, si el paciente tiene cita antes de que llegue y si ya llegó
 * y nadie lo citó para colocarlo. Al pagarlo queda anotado en los gastos de Caja.
 */
#[Fillable(['patient_id', 'doctor_id', 'description', 'laboratory', 'sent_on', 'due_on', 'received_on', 'cost', 'expense_id', 'notes', 'created_by_user_id'])]
class LabOrder extends Model
{
    /** @use HasFactory<LabOrderFactory> */
    use Auditable, HasFactory;

    /** Días que un trabajo ya recibido sigue pidiendo cita para colocarlo. */
    public const PLACE_WITHIN_DAYS = 60;

    protected function casts(): array
    {
        return [
            'sent_on' => 'date',
            'due_on' => 'date',
            'received_on' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** El gasto con que se le pagó al laboratorio. */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function status(): LabOrderStatus
    {
        return match (true) {
            $this->received_on !== null => LabOrderStatus::Received,
            $this->due_on->isBefore(today()) => LabOrderStatus::Late,
            default => LabOrderStatus::Pending,
        };
    }

    /** Se pagó y ese gasto sigue en pie (no se anuló). */
    public function isPaid(): bool
    {
        return $this->expense_id !== null && $this->expense?->voided_at === null;
    }

    /**
     * La próxima cita del paciente que todavía no pasó. La lista la trae ya calculada (withNextAppointment).
     */
    public function nextAppointmentAt(): ?CarbonImmutable
    {
        $at = array_key_exists('next_appointment_at', $this->attributes)
            ? $this->attributes['next_appointment_at']
            : self::nextAppointmentQuery($this->patient_id)->value('starts_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    /**
     * Lo que hay que resolver con este trabajo, si hay algo: "Su cita del jue. 12 nov. es antes de la entrega",
     * "Debía llegar hace 3 días" o "Ya llegó y no tiene cita para colocarlo".
     */
    public function alert(): ?string
    {
        $next = $this->nextAppointmentAt();

        return match (true) {
            $this->received_on !== null => $next === null && $this->received_on->gte(today()->subDays(self::PLACE_WITHIN_DAYS)) && ! $this->visitedSinceArrival()
                ? 'Ya llegó y no tiene cita para colocarlo'
                : null,
            $next !== null && $next->startOfDay()->lt($this->due_on) => 'Su cita del '.$next->translatedFormat('D j M').' es antes de la entrega',
            $this->due_on->isBefore(today()) => 'Debía llegar '.$this->due_on->diffForHumans(today(), ['syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW, 'parts' => 1]),
            $this->due_on->isToday() => 'Llega hoy: confirma que lo trajeron',
            default => null,
        };
    }

    /** Todavía en el laboratorio. */
    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->whereNull('received_on');
    }

    /**
     * Los que piden hacer algo hoy: llegan hoy o están atrasados, el paciente tiene cita antes de la entrega, o ya
     * llegaron y nadie lo citó para colocarlos.
     */
    #[Scope]
    protected function needingAttention(Builder $query): Builder
    {
        $appointments = fn (array $statuses): QueryBuilder => Appointment::query()
            ->toBase()
            ->whereColumn('appointments.patient_id', 'lab_orders.patient_id')
            ->whereIn('appointments.status', $statuses);

        return $query->where(fn (Builder $any) => $any
            ->where(fn (Builder $pending) => $pending
                ->whereNull('received_on')
                ->where(fn (Builder $problem) => $problem
                    ->whereDate('due_on', '<=', today())
                    ->orWhereExists($appointments(AppointmentStatus::open())->where('appointments.ends_at', '>', now())->whereColumn('appointments.starts_at', '<', 'lab_orders.due_on'))))
            ->orWhere(fn (Builder $arrived) => $arrived
                ->whereDate('received_on', '>=', today()->subDays(self::PLACE_WITHIN_DAYS))
                ->whereNotExists($appointments(AppointmentStatus::occupying())->whereColumn('appointments.starts_at', '>=', 'lab_orders.received_on'))));
    }

    /** Con la próxima cita del paciente en next_appointment_at, para no consultarla fila por fila. */
    #[Scope]
    protected function withNextAppointment(Builder $query): Builder
    {
        return $query->addSelect(['next_appointment_at' => self::nextAppointmentQuery()->select('starts_at')]);
    }

    /** Lo que sigue en el laboratorio para ese paciente y llega después de ese día: una cita ese día sería antes de la entrega. */
    #[Scope]
    protected function arrivingAfter(Builder $query, int $patientId, CarbonInterface $day): Builder
    {
        return $query->where('patient_id', $patientId)->whereNull('received_on')->whereDate('due_on', '>', $day);
    }

    private static function nextAppointmentQuery(?int $patientId = null): Builder
    {
        return Appointment::query()
            ->when(
                $patientId,
                fn (Builder $appointments) => $appointments->where('patient_id', $patientId),
                fn (Builder $appointments) => $appointments->whereColumn('appointments.patient_id', 'lab_orders.patient_id'),
            )
            ->whereIn('status', AppointmentStatus::open())
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->limit(1);
    }

    /** Vino (o tiene cita) desde que llegó el trabajo: ya se le colocó o ya está citado. */
    private function visitedSinceArrival(): bool
    {
        return Appointment::query()
            ->where('patient_id', $this->patient_id)
            ->whereIn('status', AppointmentStatus::occupying())
            ->where('starts_at', '>=', $this->received_on)
            ->exists();
    }
}
