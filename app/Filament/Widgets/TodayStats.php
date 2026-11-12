<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Pages\CashClosing;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * Los números del día, grandes y a la vista. Solo se colorea el que espera una acción (alguien sin
 * confirmar, alguien que se fue debiendo); el resto queda neutro para que lo urgente resalte.
 * Recepción ve lo cobrado (las horas libres están en "Huecos de la semana"); el doctor, sus
 * solicitudes de cambio y sus avisos (recepción los tiene en "Por hacer hoy"). Quién está en sala
 * no se repite aquí: lo dicen la sala de espera y "Tu próximo paciente". Cada número lleva a su lista.
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
                ? [$this->collectedToday($user)]
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

        $done = $today->filter(fn (AppointmentStatus $status) => $status === AppointmentStatus::Completed)->count();
        $toCome = $today->filter(fn (AppointmentStatus $status) => in_array($status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true))->count();

        return [
            'label' => 'Citas de hoy',
            'value' => $today->count(),
            // Cuánto va del día; "atendidas" siempre, para que el cero se lea como cero y no como olvido.
            'hint' => collect([
                $done === 1 ? '1 atendida' : "{$done} atendidas",
                $toCome > 0 ? "{$toCome} por llegar" : null,
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
        $owing = Patient::query()->owingAfterTodaysVisit()->count();
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
