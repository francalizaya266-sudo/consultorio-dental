<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Filament\Pages\CashClosing as CashClosingPage;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Billing\CashClosing;
use App\Services\Dashboard\DayMoment;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * Lo que toca según la hora, arriba de "Hoy". Antes de abrir: las citas de hoy que faltan confirmar y los
 * avisos por enviar. Al terminar el día: lo cobrado, quién se fue debiendo, las citas sin cerrar y el
 * cierre de caja. Durante el día no aparece: mandan la agenda y la sala. Si no hay nada que hacer, tampoco.
 */
class DayMomentCard extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.day-moment';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->managesAllAgendas() && self::content() !== null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return self::content() ?? ['moment' => DayMoment::OPEN];
    }

    /** @return array<string, mixed>|null Null cuando no hay nada que mostrar. */
    private static function content(): ?array
    {
        $now = CarbonImmutable::now();

        return match (DayMoment::at($now)) {
            DayMoment::BEFORE => self::beforeOpening($now),
            DayMoment::CLOSING => CashClosingPage::canAccess() ? self::closing($now) : null,
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private static function beforeOpening(CarbonImmutable $now): ?array
    {
        $unconfirmed = AppointmentResource::getEloquentQuery()
            ->with(['patient', 'treatment', 'doctor'])
            ->whereBetween('starts_at', [$now->startOfDay(), $now->endOfDay()])
            ->where('status', AppointmentStatus::Pending)
            ->orderBy('starts_at')
            ->get();
        $notices = WhatsappMessageResource::getEloquentQuery()->due()->count();

        if ($unconfirmed->isEmpty() && $notices === 0) {
            return null;
        }

        return [
            'moment' => DayMoment::BEFORE,
            'unconfirmed' => $unconfirmed->map(fn (Appointment $appointment) => self::row($appointment)),
            'notices' => $notices,
            'noticesUrl' => WhatsappMessageResource::getUrl('index'),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function closing(CarbonImmutable $now): ?array
    {
        $closing = new CashClosing($now->startOfDay());
        $record = $closing->record();
        $unclosed = $closing->unclosedAppointments();
        $pending = $closing->pending();

        if ($record !== null && $unclosed->isEmpty() && $pending->isEmpty()) {
            return null;
        }

        return [
            'moment' => DayMoment::CLOSING,
            'collected' => $closing->total(),
            'pending' => $pending,
            'pendingTotal' => $closing->pendingTotal(),
            'unclosed' => $unclosed->map(fn (Appointment $appointment) => self::row($appointment)),
            'record' => $record,
            'closingUrl' => CashClosingPage::getUrl(),
        ];
    }

    /** @return array{time: string, name: string, detail: string, url: string} */
    private static function row(Appointment $appointment): array
    {
        return [
            'time' => $appointment->starts_at->format('H:i'),
            'name' => $appointment->patient->full_name,
            'detail' => collect([$appointment->treatment?->name, $appointment->doctor?->name])->filter()->implode(' · '),
            'url' => AppointmentResource::getUrl('view', ['record' => $appointment]),
        ];
    }
}
