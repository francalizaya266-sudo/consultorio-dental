<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Pages\Agenda;
use App\Filament\Pages\CashClosing;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Services\Dashboard\WeekGaps as WeekGapsService;
use App\Support\Dates;
use App\Support\Money;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los cuatro números del día, grandes y a la vista. Solo se colorea el que espera una acción
 * (alguien sin confirmar, alguien que se fue debiendo); el resto queda neutro para que lo urgente
 * resalte. Recepción ve lo cobrado y las horas libres de la semana; el doctor, sus solicitudes de
 * cambio y sus avisos (recepción los tiene en "Por hacer hoy"). Cada número lleva a su lista.
 */
class TodayStats extends Widget
{
    /** Los números del día se ven apenas abre "Hoy", sin un recuadro vacío mientras carga. */
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.today-stats';

    protected int|string|array $columnSpan = 'full';

    /** @return array{stats: list<array{label: string, value: string|int, hint: string, color: string, icon: string, url: string}>} */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return ['stats' => array_values(array_filter([
            $this->appointments(),
            $this->unconfirmed(),
            ...($user->managesAllAgendas()
                ? [$this->collectedToday($user), $this->freeTime()]
                : [$this->pendingRequests(), $this->dueMessages()]),
        ]))];
    }

    /** @return array{label: string, value: int, hint: string, color: string, icon: string, url: string} */
    private function appointments(): array
    {
        $today = AppointmentResource::getEloquentQuery()
            ->whereDate('starts_at', today())
            ->whereIn('status', AppointmentStatus::occupying())
            ->pluck('status');

        $count = fn (AppointmentStatus $status) => $today->filter(fn (AppointmentStatus $s) => $s === $status)->count();
        $done = $count(AppointmentStatus::Completed);
        $waiting = $count(AppointmentStatus::CheckedIn);
        $inProgress = $count(AppointmentStatus::InProgress);

        return [
            'label' => 'Citas de hoy',
            'value' => $today->count(),
            // Lo que está pasando primero; "atendidas" siempre, para que el cero se lea como cero y no como olvido.
            'hint' => collect([
                $waiting > 0 ? "{$waiting} en sala" : null,
                $inProgress > 0 ? "{$inProgress} en atención" : null,
                $done === 1 ? '1 atendida' : "{$done} atendidas",
            ])->filter()->implode(' · '),
            'color' => 'gray',
            'icon' => 'heroicon-o-calendar-days',
            'url' => AppointmentResource::getUrl('index', ['tab' => 'today']),
        ];
    }

    /** @return array{label: string, value: int, hint: string, color: string, icon: string, url: string} */
    private function unconfirmed(): array
    {
        $count = AppointmentResource::getEloquentQuery()
            ->where('status', AppointmentStatus::Pending)
            ->whereBetween('starts_at', [now(), today()->addDays(2)])
            ->count();

        return [
            'label' => 'Por confirmar',
            'value' => $count,
            'hint' => $count > 0 ? 'Hoy y mañana · reenvía el aviso o llama' : 'Hoy y mañana, todo confirmado',
            'color' => $count > 0 ? 'warning' : 'gray',
            'icon' => 'heroicon-o-phone',
            'url' => AppointmentResource::getUrl('index', ['tab' => 'pending']),
        ];
    }

    /**
     * Lo cobrado hoy y quién se fue sin pagar. Lleva al cierre del día si nadie quedó debiendo.
     *
     * @return array{label: string, value: string, hint: string, color: string, icon: string, url: string}|null
     */
    private function collectedToday(User $user): ?array
    {
        if (! $user->can('viewAny', Payment::class)) {
            return null;
        }

        $collected = Payment::query()->whereNull('voided_at')->whereDate('paid_at', today())->sum('amount');
        $owing = Patient::query()
            ->owing()
            ->whereHas('appointments', fn (Builder $query) => $query->whereDate('starts_at', today())->where('status', AppointmentStatus::Completed))
            ->count();
        $finished = Appointment::query()->whereDate('starts_at', today())->where('status', AppointmentStatus::Completed)->exists();

        return [
            'label' => 'Cobrado hoy',
            'value' => Money::format($collected),
            'hint' => match (true) {
                $owing === 0 && ! $finished => 'Aún no se terminó ninguna atención',
                $owing === 0 => 'Nadie se fue debiendo',
                $owing === 1 => '1 paciente atendido hoy debe',
                default => "{$owing} pacientes atendidos hoy deben",
            },
            'color' => $owing > 0 ? 'warning' : 'gray',
            'icon' => 'heroicon-o-banknotes',
            'url' => $owing > 0 ? AppointmentResource::getUrl('index', ['tab' => 'today']) : CashClosing::getUrl(),
        ];
    }

    /**
     * Cuánto tiempo libre queda en la agenda de hoy a 7 días: lo que se puede llenar.
     *
     * @return array{label: string, value: string, hint: string, color: string, icon: string, url: string}
     */
    private function freeTime(): array
    {
        $days = app(WeekGapsService::class)->days();
        $free = array_sum(array_column($days, 'free_minutes'));
        $waiting = array_sum(array_column($days, 'waiting'));

        return [
            'label' => 'Horas libres',
            'value' => $free > 0 ? Dates::duration($free) : 'Ninguna',
            'hint' => $waiting > 0 ? 'Esta semana · hay lista de espera' : 'De hoy a 7 días',
            'color' => 'gray',
            'icon' => 'heroicon-o-clock',
            'url' => Agenda::getUrl(['mode' => 'week']),
        ];
    }

    /** @return array{label: string, value: int, hint: string, color: string, icon: string, url: string} */
    private function pendingRequests(): array
    {
        $count = RescheduleRequestResource::getEloquentQuery()
            ->where('status', RescheduleRequestStatus::Pending)
            ->count();

        return [
            'label' => 'Solicitudes de cambio',
            'value' => $count,
            'hint' => $count > 0 ? 'Aprueba un horario con un clic' : 'Nada pendiente',
            'color' => $count > 0 ? 'warning' : 'gray',
            'icon' => 'heroicon-o-arrows-right-left',
            'url' => RescheduleRequestResource::getUrl('index'),
        ];
    }

    /** @return array{label: string, value: int, hint: string, color: string, icon: string, url: string} */
    private function dueMessages(): array
    {
        $count = WhatsappMessageResource::getEloquentQuery()->due()->count();

        return [
            'label' => 'Avisos por enviar',
            'value' => $count,
            'hint' => $count > 0 ? 'Listos para WhatsApp' : 'Nada pendiente',
            'color' => $count > 0 ? 'warning' : 'gray',
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'url' => WhatsappMessageResource::getUrl('index'),
        ];
    }
}
