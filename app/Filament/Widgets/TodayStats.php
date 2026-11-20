<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\RescheduleRequestStatus;
use App\Enums\TodayView;
use App\Filament\Concerns\RefreshesWithToday;
use App\Filament\Pages\Agenda;
use App\Filament\Pages\CashClosing as CashClosingPage;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CashClosing;
use App\Support\Dates;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los números del día: cuatro, en una fila y a la vista. Solo se marca el que espera una acción (alguien sin
 * confirmar, alguien que se fue debiendo) o una espera larga; el resto queda neutro para que lo urgente resalte.
 * En "Toda la clínica": las citas, quién está en sala ahora (con scroll la sala puede quedar abajo, y una espera
 * larga no debe pasar desapercibida), por confirmar y lo cobrado. En "Mi consulta": mis citas, mis por confirmar,
 * las solicitudes de cambio y los avisos por enviar; quién me espera ya lo dice "Tu próximo paciente".
 * Cada número lleva a su lista.
 */
class TodayStats extends Widget
{
    use RefreshesWithToday;

    /** Los números del día se ven apenas abre "Hoy", sin un recuadro vacío mientras carga. */
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.today-stats';

    protected int|string|array $columnSpan = 'full';

    /** @return array{stats: list<array{label: string, value: string|int, hint: string, color: string, icon: string, url: string}>} */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        if (TodayView::current($user) === TodayView::Clinic) {
            return ['stats' => array_values(array_filter([
                $this->appointments(),
                $this->waitingRoom(),
                $this->unconfirmed(),
                $this->collectedToday($user),
            ]))];
        }

        // Al doctor la lista ya le trae solo lo suyo; al administrador que atiende, "Mi consulta" la acota a su agenda.
        $doctorId = $user->managesAllAgendas() ? $user->doctorId() : null;

        return ['stats' => [
            $this->appointments($doctorId, 'Mis citas de hoy'),
            $this->unconfirmed($doctorId),
            $this->pendingRequests(),
            $this->dueMessages(),
        ]];
    }

    /** @return array{label: string, value: int, hint: string, color: string, icon: string, url: string} */
    private function appointments(?int $doctorId = null, string $label = 'Citas de hoy'): array
    {
        // Las mismas que lista "Agenda de hoy", justo debajo: todas menos las canceladas.
        $today = AppointmentResource::getEloquentQuery()
            ->when($doctorId, fn (Builder $query) => $query->where('doctor_id', $doctorId))
            ->whereDate('starts_at', today())
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->pluck('status');

        $done = $today->filter(fn (AppointmentStatus $status) => $status === AppointmentStatus::Completed)->count();
        $toCome = $today->filter(fn (AppointmentStatus $status) => $status->isChangeable())->count();
        $missed = $today->filter(fn (AppointmentStatus $status) => $status === AppointmentStatus::NoShow)->count();

        return [
            'label' => $label,
            'value' => $today->count(),
            // Cuánto va del día; "atendidas" siempre, para que el cero se lea como cero y no como olvido.
            'hint' => collect([
                $done === 1 ? '1 atendida' : "{$done} atendidas",
                $toCome > 0 ? "{$toCome} por llegar" : null,
                match ($missed) {
                    0 => null,
                    1 => '1 no asistió',
                    default => "{$missed} no asistieron",
                },
            ])->filter()->implode(' · '),
            'color' => 'gray',
            'icon' => 'heroicon-o-calendar-days',
            // Las citas de hoy se ven en la Agenda: Citas ya no repite ese día en una pestaña.
            'url' => Agenda::getUrl(),
        ];
    }

    /** @return array{label: string, value: int, hint: string, color: string, icon: string, url: string} */
    private function unconfirmed(?int $doctorId = null): array
    {
        $count = AppointmentResource::getEloquentQuery()
            ->when($doctorId, fn (Builder $query) => $query->where('doctor_id', $doctorId))
            ->awaitingConfirmation()
            ->todayOrTomorrow()
            ->count();

        return [
            'label' => 'Por confirmar',
            'value' => $count,
            'hint' => $count > 0 ? 'Hoy y mañana · reenvía el aviso o llama' : 'Hoy y mañana, todo confirmado',
            'color' => $count > 0 ? 'warning' : 'gray',
            'icon' => 'heroicon-o-phone',
            // La pestaña trae todas las que faltan confirmar; con las fechas en el filtro quedan las de hoy y mañana, que son las que se cuentan aquí.
            'url' => AppointmentResource::toConfirmUrl(today(), today()->addDay(), doctorId: $doctorId),
        ];
    }

    /**
     * Quién está en sala ahora y cuánto lleva el que más espera: las mismas personas que la sala de espera, a la
     * que lleva. Desde TodayAppointments::LONG_WAIT_MINUTES se marca en rojo.
     *
     * @return array{label: string, value: int, hint: string, color: string, icon: string, url: string}
     */
    private function waitingRoom(): array
    {
        $longest = WaitingRoom::waitingMinutes();

        return [
            'label' => 'En sala ahora',
            'value' => $longest->count(),
            'hint' => $longest->isEmpty() ? 'Nadie esperando' : 'El que más espera: '.Dates::duration($longest->max()),
            'color' => $longest->max() >= TodayAppointments::LONG_WAIT_MINUTES ? 'danger' : 'gray',
            'icon' => 'heroicon-o-user-group',
            'url' => '#'.WaitingRoom::ANCHOR,
        ];
    }

    /**
     * Lo cobrado hoy y lo que falta cobrar de lo de hoy, con las mismas cuentas que el cierre del día: lleva
     * ahí, donde está cada persona que debe con su "Cobrar", y el número y la lista coinciden.
     *
     * @return array{label: string, value: string, hint: string, color: string, icon: string, url: string}|null
     */
    private function collectedToday(User $user): ?array
    {
        if (! $user->can('viewAny', Payment::class)) {
            return null;
        }

        $closing = new CashClosing(CarbonImmutable::today());
        $owing = $closing->pending()->count();
        $finished = Appointment::query()->whereDate('starts_at', today())->where('status', AppointmentStatus::Completed)->exists();

        return [
            'label' => 'Cobrado hoy',
            'value' => Money::format($closing->total()),
            'hint' => match (true) {
                $owing === 0 && ! $finished => 'Aún no se terminó ninguna atención',
                $owing === 0 => 'Nadie se fue debiendo',
                $owing === 1 => 'Falta cobrar '.Money::format($closing->pendingTotal()).' a 1 paciente',
                default => 'Falta cobrar '.Money::format($closing->pendingTotal())." a {$owing} pacientes",
            },
            'color' => $owing > 0 ? 'warning' : 'gray',
            'icon' => 'heroicon-o-banknotes',
            'url' => CashClosingPage::getUrl(),
        ];
    }

    /** @return array{label: string, value: int, hint: string, color: string, icon: string, url: string} */
    private function pendingRequests(): array
    {
        $count = RescheduleRequestResource::getEloquentQuery()
            ->where('status', RescheduleRequestStatus::Pending)
            ->count();

        return [
            'label' => 'Cambios pedidos',
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
