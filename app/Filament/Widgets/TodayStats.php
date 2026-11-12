<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\RescheduleRequestStatus;
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
use App\Support\Money;
use Carbon\CarbonImmutable;
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
        // Las mismas que lista "Agenda de hoy", justo debajo: todas menos las canceladas.
        $today = AppointmentResource::getEloquentQuery()
            ->whereDate('starts_at', today())
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->pluck('status');

        $done = $today->filter(fn (AppointmentStatus $status) => $status === AppointmentStatus::Completed)->count();
        $toCome = $today->filter(fn (AppointmentStatus $status) => $status->isChangeable())->count();
        $missed = $today->filter(fn (AppointmentStatus $status) => $status === AppointmentStatus::NoShow)->count();

        return [
            'label' => 'Citas de hoy',
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
    private function unconfirmed(): array
    {
        $count = AppointmentResource::getEloquentQuery()
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
            'url' => AppointmentResource::toConfirmUrl(today(), today()->addDay()),
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
