<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Enums\AppointmentStatus;
use App\Enums\MessageStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\PatientSummary;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\Charge;
use App\Models\Payment;
use App\Models\WhatsappMessage;
use App\Services\Billing\BillingService;
use App\Support\Money;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class AppointmentInfolist
{
    /** @var array<string, string> Los pasos de una cita que sí se da, con el dato que dice cuándo pasó cada uno. */
    private const STEPS = [
        'pending' => 'created_at',
        'confirmed' => 'confirmed_at',
        'checked_in' => 'checked_in_at',
        'in_progress' => 'started_at',
        'completed' => 'finished_at',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                // Lo mismo que bajo el nombre en su ficha: el siguiente paso, saldo, próxima cita, control y cómo escribirle.
                View::make('filament.patients.summary')
                    ->viewData(fn (Appointment $record, $livewire) => PatientSummary::viewData($record->patient, auth()->user(), $livewire, withWhatsapp: false))
                    ->columnSpanFull(),
                // Dos columnas que crecen cada una por su lado: una tarjeta alta al costado no abre un hueco en la otra.
                Group::make([
                    self::details(),
                    self::toCharge(),
                    self::billing(),
                ])->columnSpan(2),
                Group::make([
                    self::patientVisits(),
                    self::history(),
                ])->columnSpan(1),
            ]);
    }

    /** En qué va la cita y sus datos. El nombre, el día y el tratamiento ya están en el título: aquí no se repiten. */
    private static function details(): Section
    {
        return Section::make('Cita')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->schema([
                View::make('filament.appointments.details')
                    ->viewData(fn (Appointment $record) => self::detailsData($record)),
            ]);
    }

    /**
     * El camino de la cita (reservada, confirmada, llegó, en atención, atendida), con la hora de cada paso,
     * y sus datos con un ícono cada uno.
     *
     * @return array{steps: list<array{label: string, at: string|null, state: 'done'|'current'|'skipped'|'todo'}>, closed: array{label: string, detail: string|null, color: string}|null, warning: string|null, facts: list<array{icon: Heroicon, label: string, value: string, hint: string|null}>, notes: list<array{label: string, text: string}>}
     */
    public static function detailsData(Appointment $appointment): array
    {
        $appointment->loadMissing(['patient.account', 'treatment', 'doctor', 'campaign', 'createdBy', 'planItem']);

        return [
            'steps' => self::steps($appointment),
            'closed' => self::closedAs($appointment),
            'warning' => $appointment->needsCall() ? 'No confirmó: conviene llamar.' : null,
            'facts' => self::facts($appointment),
            'notes' => array_values(array_filter([
                filled($appointment->patient_note) ? ['label' => 'Nota del paciente', 'text' => $appointment->patient_note] : null,
                filled($appointment->internal_notes) ? ['label' => 'Notas internas', 'text' => $appointment->internal_notes] : null,
            ])),
        ];
    }

    /**
     * Cada paso con su estado: hecho, el de ahora, saltado (por ejemplo, llegó sin haber confirmado) o por venir.
     *
     * @return list<array{label: string, at: string|null, state: 'done'|'current'|'skipped'|'todo'}>
     */
    private static function steps(Appointment $appointment): array
    {
        $labels = ['Reservada', 'Confirmada', 'Llegó', 'En atención', 'Atendida'];
        $columns = array_values(self::STEPS);
        $missed = in_array($appointment->status, AppointmentStatus::missed(), true);

        // Si faltó o se canceló, el camino llega hasta el último paso que sí se dio.
        $current = $missed
            ? (int) collect($columns)->filter(fn (string $column) => $appointment->{$column} !== null)->keys()->last()
            : (int) array_search($appointment->status->value, array_keys(self::STEPS), true);
        $finished = $appointment->status === AppointmentStatus::Completed;

        return collect($labels)->map(function (string $label, int $index) use ($appointment, $columns, $current, $finished, $missed) {
            $at = $index === 0 ? self::bookedAt($appointment) : $appointment->{$columns[$index]};

            return [
                'label' => $label,
                'at' => $at && $index <= $current ? self::stepTime($at, $appointment) : null,
                'state' => match (true) {
                    $index > $current => 'todo',
                    $index === $current && ! $finished && ! $missed => 'current',
                    $index > 0 && $at === null => 'skipped',
                    default => 'done',
                },
            ];
        })->all();
    }

    /**
     * Cuándo se reservó. Una cita cargada después de su día (traída de otro sistema, por ejemplo) no lo sabe:
     * su fecha de registro no es la de la reserva.
     */
    private static function bookedAt(Appointment $appointment): ?CarbonInterface
    {
        $createdAt = $appointment->created_at;

        return $createdAt && $createdAt->lte($appointment->starts_at->copy()->endOfDay()) ? $createdAt : null;
    }

    /** La hora sola si fue el día de la cita; con el día si fue otro (se reservó antes, por ejemplo). */
    private static function stepTime(CarbonInterface $at, Appointment $appointment): string
    {
        return $at->isSameDay($appointment->starts_at) ? $at->format('H:i') : $at->translatedFormat('j M · H:i');
    }

    /**
     * Cómo terminó una cita que no se dio: faltó o se canceló, con quién, cuándo y por qué.
     *
     * @return array{label: string, detail: string|null, color: string}|null
     */
    private static function closedAs(Appointment $appointment): ?array
    {
        return match ($appointment->status) {
            AppointmentStatus::NoShow => [
                'label' => 'No asistió',
                'detail' => $appointment->no_show_at ? 'Se marcó el '.$appointment->no_show_at->translatedFormat('j \d\e F \a \l\a\s H:i').'.' : null,
                'color' => 'danger',
            ],
            AppointmentStatus::Cancelled => [
                'label' => 'Cancelada'.($appointment->cancelled_by ? ' por '.mb_strtolower($appointment->cancelled_by->getLabel()) : ''),
                'detail' => collect([
                    $appointment->cancelled_at?->translatedFormat('j \d\e F \a \l\a\s H:i'),
                    'Motivo: '.($appointment->cancellation_reason ?: 'sin motivo'),
                ])->filter()->implode(' · '),
                'color' => 'gray',
            ],
            default => null,
        };
    }

    /**
     * Los datos de la cita, cada uno con su ícono. Lo que no tiene valor no aparece.
     *
     * @return list<array{icon: Heroicon, label: string, value: string, hint: string|null}>
     */
    private static function facts(Appointment $appointment): array
    {
        $minutes = (int) $appointment->starts_at->diffInMinutes($appointment->ends_at, true);
        $phone = $appointment->patient->account?->phone;
        $promoCode = $appointment->promo_code ?? $appointment->campaign?->promo_code;

        return array_values(array_filter([
            [
                'icon' => Heroicon::OutlinedCalendarDays,
                'label' => 'Día',
                'value' => ucfirst($appointment->starts_at->translatedFormat('l j \d\e F \d\e Y')),
                'hint' => self::howFarIs($appointment->starts_at),
            ],
            [
                'icon' => Heroicon::OutlinedClock,
                'label' => 'Hora',
                'value' => $appointment->starts_at->format('H:i').' – '.$appointment->ends_at->format('H:i'),
                'hint' => $minutes.' min (+'.$appointment->buffer_minutes.' de esterilización)',
            ],
            [
                'icon' => Heroicon::OutlinedSparkles,
                'label' => 'Tratamiento',
                'value' => $appointment->treatment->name,
                'hint' => $appointment->planItem ? 'Es una sesión de su plan de tratamiento' : null,
            ],
            [
                'icon' => Heroicon::OutlinedUserCircle,
                'label' => 'Doctor',
                'value' => $appointment->doctor->name,
                'hint' => self::realTimes($appointment),
            ],
            [
                'icon' => Heroicon::OutlinedDevicePhoneMobile,
                'label' => 'Celular',
                'value' => $phone ? Phone::display($phone) : 'Sin celular',
                'hint' => null,
            ],
            [
                'icon' => Heroicon::OutlinedPencilSquare,
                'label' => 'Reservada por',
                'value' => $appointment->booked_via->getLabel(),
                'hint' => collect([$appointment->createdBy?->name, self::bookedAt($appointment)?->translatedFormat('j M Y')])->filter()->implode(' · ') ?: null,
            ],
            $appointment->origin ? [
                'icon' => Heroicon::OutlinedMegaphone,
                'label' => '¿Cómo nos encontró?',
                'value' => $appointment->origin->getLabel(),
                'hint' => null,
            ] : null,
            filled($promoCode) ? [
                'icon' => Heroicon::OutlinedTicket,
                'label' => 'Código de campaña',
                'value' => (string) $promoCode,
                'hint' => $appointment->campaign?->name,
            ] : null,
            $appointment->fixed_date ? [
                'icon' => Heroicon::OutlinedLockClosed,
                'label' => 'Fecha fija',
                'value' => 'El paciente no la mueve solo',
                'hint' => null,
            ] : null,
            $appointment->is_overbooked ? [
                'icon' => Heroicon::OutlinedSquare2Stack,
                'label' => 'Sobrecupo',
                'value' => 'Se agendó a propósito encima de otra cita',
                'hint' => null,
            ] : null,
            ($series = $appointment->seriesPosition()) ? [
                'icon' => Heroicon::OutlinedArrowPath,
                'label' => 'Se repite',
                'value' => "Cita {$series['position']} de {$series['total']} de su serie",
                'hint' => null,
            ] : null,
        ]));
    }

    /** "Hoy", "Mañana", "Ayer", "En 5 días" o "Hace 2 meses": qué tan cerca está el día de la cita. */
    private static function howFarIs(CarbonInterface $startsAt): string
    {
        $days = (int) today()->diffInDays($startsAt->copy()->startOfDay());

        return match ($days) {
            0 => 'Hoy',
            1 => 'Mañana',
            -1 => 'Ayer',
            default => ucfirst($startsAt->copy()->startOfDay()->diffForHumans(today(), ['syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW])),
        };
    }

    /** Lo que de verdad pasó ese día: cuánto esperó en la sala y cuánto duró la atención. */
    private static function realTimes(Appointment $appointment): ?string
    {
        $waited = $appointment->checked_in_at && $appointment->started_at && $appointment->started_at->gte($appointment->checked_in_at)
            ? 'esperó '.(int) $appointment->checked_in_at->diffInMinutes($appointment->started_at).' min'
            : null;
        $lasted = $appointment->started_at && $appointment->finished_at && $appointment->finished_at->gte($appointment->started_at)
            ? 'la atención duró '.(int) $appointment->started_at->diffInMinutes($appointment->finished_at).' min'
            : null;

        return ucfirst(collect([$waited, $lasted])->filter()->implode(' · ')) ?: null;
    }

    /** Al costado, las otras citas del mismo paciente: se pasa de una a otra sin volver a buscarlo. */
    private static function patientVisits(): Section
    {
        return Section::make('Sus citas')
            ->icon(Heroicon::OutlinedQueueList)
            ->schema([
                View::make('filament.appointments.patient-visits')
                    ->viewData(fn (Appointment $record) => self::visitsAround($record)),
            ]);
    }

    /**
     * Las dos que siguen y las cuatro anteriores, con esta en su lugar.
     *
     * @return array{rows: list<array{title: string, when: string, status: string, color: string, url: string|null, current: bool}>, total: int, profile_url: string|null, new_url: string|null}
     */
    public static function visitsAround(Appointment $appointment): array
    {
        $patient = $appointment->patient;
        $query = fn () => AppointmentResource::getEloquentQuery()->whereBelongsTo($patient)->whereKeyNot($appointment->getKey())->with('treatment');
        $after = $query()->where('starts_at', '>', $appointment->starts_at)->orderBy('starts_at')->limit(2)->get();
        $before = $query()->where('starts_at', '<=', $appointment->starts_at)->orderByDesc('starts_at')->limit(4)->get();

        $row = fn (Appointment $visit, bool $current = false) => [
            'title' => $visit->treatment->name,
            'when' => ucfirst($visit->starts_at->translatedFormat('D d/m/Y · H:i')),
            'status' => $visit->status->getLabel(),
            'color' => $visit->status->getColor(),
            'url' => $current ? null : AppointmentResource::getUrl('view', ['record' => $visit]),
            'current' => $current,
        ];

        $rows = [
            ...$after->reverse()->map(fn (Appointment $visit) => $row($visit))->values()->all(),
            $row($appointment, current: true),
            ...$before->map(fn (Appointment $visit) => $row($visit))->all(),
        ];
        $total = $query()->count() + 1;

        return [
            'rows' => $rows,
            'total' => $total,
            // La ficha ya tiene su botón arriba: aquí solo se ofrece cuando guarda más citas de las que caben en la tarjeta.
            'profile_url' => $total > count($rows) && PatientResource::canView($patient) ? PatientResource::getUrl('view', ['record' => $patient]) : null,
            'new_url' => AppointmentResource::canCreate() ? AppointmentResource::newUrl(['patient' => $patient->getKey()]) : null,
        ];
    }

    /**
     * Antes de atenderla: lo que se espera cobrar ese día, para que recepción lo tenga listo
     * (la cuota que vence, la sesión del plan o el tratamiento con su promoción, y el saldo).
     */
    private static function toCharge(): Section
    {
        return Section::make('Para cobrar ese día')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (Appointment $record) => auth()->user()->can('viewAny', Charge::class)
                && in_array($record->status, AppointmentStatus::open(), true))
            ->schema([
                TextEntry::make('estimate')
                    ->label('Detalle')
                    ->hiddenLabel()
                    ->state(fn (Appointment $record) => collect(app(BillingService::class)->estimateFor($record)['lines'])
                        ->map(fn (array $line) => $line['label'].($line['amount'] === null ? '' : ': '.Money::format($line['amount'])))
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Sin precio en el catálogo para este tratamiento.'),
                TextEntry::make('estimate_total')
                    ->label('Cobrar')
                    ->state(fn (Appointment $record) => Money::format(app(BillingService::class)->estimateFor($record)['total']))
                    ->weight('bold')
                    ->size(TextSize::Large),
            ]);
    }

    /** Lo cargado y lo pagado por la cita, para recepción y administración. */
    private static function billing(): Section
    {
        return Section::make('Cobro')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (Appointment $record) => auth()->user()->can('viewAny', Charge::class)
                && ($record->charges()->exists() || $record->payments()->exists()))
            ->schema([
                View::make('filament.appointments.billing')
                    ->viewData(fn (Appointment $record) => self::billingData($record)),
            ]);
    }

    /**
     * Las cuentas de esta cita, separadas del saldo de toda la vida del paciente: cuánto se cargó, cuánto pagó
     * y cuánto falta. Lo que falta es lo que quedó sin pagar en la cita, sin pasar de lo que el paciente todavía debe:
     * si después se puso al día, la cita figura pagada.
     *
     * @return array{charged: float, charges: list<array{label: string, amount: string, note: string|null}>, paid: float, payments: list<array{label: string, amount: string, url: string}>, due: float, percent: int, balance: float, balance_label: string, account_url: string|null}
     */
    public static function billingData(Appointment $appointment): array
    {
        $patient = $appointment->patient;
        $charges = $appointment->charges()->valid()->orderBy('id')->get();
        $payments = $appointment->payments()->whereNull('voided_at')->orderBy('paid_at')->get();
        $charged = round($charges->sum(fn (Charge $charge) => $charge->net()), 2);
        $paid = round((float) $payments->sum('amount'), 2);
        $balance = $patient->balance();
        $due = round(max(0, min($charged - $paid, $balance)), 2);

        return [
            'charged' => $charged,
            'charges' => $charges->map(fn (Charge $charge) => [
                'label' => $charge->description,
                'amount' => Money::format($charge->net()),
                'note' => (float) $charge->discount > 0 ? 'con '.Money::format($charge->discount).' de descuento' : null,
            ])->all(),
            'paid' => $paid,
            'payments' => $payments->map(fn (Payment $payment) => [
                'label' => $payment->method->getLabel().' · '.$payment->paid_at->format('d/m H:i'),
                'amount' => Money::format($payment->amount),
                'url' => Filament::getPanel('admin')->route('print.receipt', ['payment' => $payment]),
            ])->all(),
            'due' => $due,
            'percent' => $charged > 0 ? (int) round(max(0, min(1, ($charged - $due) / $charged)) * 100) : 100,
            'balance' => $balance,
            'balance_label' => Money::balance($balance),
            'account_url' => PatientResource::canView($patient) ? PatientResource::getUrl('view', ['record' => $patient]) : null,
        ];
    }

    /** Todo lo que pasó con la cita, en orden: nunca queda vacío, porque al menos se reservó. */
    private static function history(): Section
    {
        return Section::make('Historial')
            ->icon(Heroicon::OutlinedClock)
            ->schema([
                View::make('filament.appointments.history')
                    ->viewData(fn (Appointment $record) => ['rows' => self::historyRows($record)]),
            ]);
    }

    /**
     * La línea de tiempo de la cita: sus cambios, los avisos de WhatsApp y los pagos. Un paso que no quedó anotado
     * como cambio (citas antiguas o cargadas de otro sistema) se arma con la hora que guarda la propia cita.
     *
     * @return list<array{label: string, meta: string, tone: string}>
     */
    public static function historyRows(Appointment $appointment): array
    {
        $appointment->loadMissing('createdBy');
        $events = $appointment->events()->with('user')->orderBy('id')->get();

        $rows = $events->map(fn (AppointmentEvent $event) => [
            'at' => $event->created_at,
            'label' => self::eventLabel($event),
            'who' => $event->actor === 'patient' ? 'Paciente' : ($event->user?->name ?? 'Sistema'),
            'notes' => $event->notes,
            'tone' => self::eventTone($event->type),
        ]);

        $recorded = $events->pluck('type')->all();
        $fallbacks = [
            'created' => [self::bookedAt($appointment), $appointment->createdBy?->name ?? $appointment->booked_via->getLabel()],
            'confirmed' => [$appointment->confirmed_at, $appointment->confirmed_via?->getLabel()],
            'checked_in' => [$appointment->checked_in_at, null],
            'in_progress' => [$appointment->started_at, null],
            'completed' => [$appointment->finished_at, null],
            'no_show' => [$appointment->no_show_at, null],
            'cancelled' => [$appointment->cancelled_at, $appointment->cancelled_by?->getLabel()],
        ];

        foreach ($fallbacks as $type => [$at, $who]) {
            if ($at !== null && ! in_array($type, $recorded, true)) {
                $rows->push([
                    'at' => $at,
                    'label' => self::eventLabel(new AppointmentEvent(['type' => $type])),
                    'who' => $who,
                    'notes' => $type === 'cancelled' ? $appointment->cancellation_reason : null,
                    'tone' => self::eventTone($type),
                ]);
            }
        }

        // Sin fecha de reserva confiable, igual se dice quién la registró: va primero y sin hora.
        if (! in_array('created', $recorded, true) && self::bookedAt($appointment) === null) {
            $rows->push([
                'at' => null,
                'label' => 'Cita registrada',
                'who' => $appointment->createdBy?->name ?? $appointment->booked_via->getLabel(),
                'notes' => null,
                'tone' => 'gray',
            ]);
        }

        return $rows
            ->concat(self::messageRows($appointment))
            ->concat(self::paymentRows($appointment))
            ->sortBy(fn (array $row) => $row['at']?->getTimestamp() ?? 0)
            ->map(fn (array $row) => [
                'label' => $row['label'],
                'meta' => collect([$row['at']?->translatedFormat('j M Y · H:i'), $row['who'], $row['notes']])->filter()->implode(' · '),
                'tone' => $row['tone'],
            ])
            ->values()
            ->all();
    }

    /**
     * Los avisos de WhatsApp de la cita: los enviados, con quién los envió, y los que siguen esperando en Avisos.
     *
     * @return Collection<int, array{at: CarbonInterface, label: string, who: string|null, notes: string|null, tone: string}>
     */
    private static function messageRows(Appointment $appointment): Collection
    {
        return $appointment->messages()
            ->whereIn('status', [MessageStatus::Sent, MessageStatus::Pending])
            ->with('sentBy')
            ->get()
            ->map(fn (WhatsappMessage $message) => [
                'at' => $message->sent_at ?? $message->created_at,
                'label' => ($message->status === MessageStatus::Sent ? 'Aviso enviado: ' : 'Aviso por enviar: ').mb_strtolower($message->template_key->getLabel()),
                'who' => $message->status === MessageStatus::Sent ? ($message->sentBy?->name ?? 'Sistema') : null,
                'notes' => null,
                'tone' => $message->status === MessageStatus::Sent ? 'success' : 'warning',
            ]);
    }

    /**
     * Los pagos registrados en la cita, solo para quien puede ver los cobros.
     *
     * @return Collection<int, array{at: CarbonInterface, label: string, who: string|null, notes: string|null, tone: string}>
     */
    private static function paymentRows(Appointment $appointment): Collection
    {
        if (! auth()->user()->can('viewAny', Charge::class)) {
            return collect();
        }

        return $appointment->payments()->whereNull('voided_at')->get()->map(fn (Payment $payment) => [
            'at' => $payment->paid_at,
            'label' => 'Pagó '.Money::format($payment->amount),
            'who' => $payment->method->getLabel(),
            'notes' => null,
            'tone' => 'success',
        ]);
    }

    private static function eventLabel(AppointmentEvent $event): string
    {
        $label = match ($event->type) {
            'created' => 'Cita creada',
            'confirmed' => 'Confirmada',
            'rescheduled' => 'Horario cambiado',
            'changed' => 'Tratamiento o duración cambiados',
            'checked_in' => 'Llegó al consultorio',
            'in_progress' => 'Pasó a atención',
            'completed' => 'Atendida',
            'no_show' => 'No asistió',
            'cancelled' => 'Cancelada',
            'reschedule_requested' => 'Pidió cambiar el horario',
            'reschedule_rejected' => 'Cambio no aprobado',
            'reschedule_expired' => 'Solicitud de cambio cerrada',
            'undone' => 'Se deshizo: '.match ($event->meta['step'] ?? null) {
                'no_show' => 'no asistió',
                'cancelled' => 'la cancelación',
                'checked_in' => 'llegó al consultorio',
                'confirmed' => 'la confirmación',
                default => 'el último paso',
            },
            default => ucfirst(str_replace('_', ' ', $event->type)),
        };

        if ($event->from_starts_at && $event->to_starts_at) {
            $label .= ': '.$event->from_starts_at->translatedFormat('d/m H:i').' → '.$event->to_starts_at->translatedFormat('d/m H:i');
        }

        return $label;
    }

    /** El color del punto de cada cambio en el historial, el mismo de su estado en la agenda. */
    private static function eventTone(string $type): string
    {
        return match ($type) {
            'confirmed', 'completed' => 'success',
            'checked_in' => 'info',
            'in_progress' => 'primary',
            'no_show', 'reschedule_rejected' => 'danger',
            'rescheduled', 'changed', 'reschedule_requested' => 'warning',
            default => 'gray',
        };
    }
}
