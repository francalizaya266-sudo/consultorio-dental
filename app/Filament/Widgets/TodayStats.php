<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Patient;
use App\Models\Payment;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/** Números del día. Cada uno lleva a la lista donde se resuelve. */
class TodayStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    /** En el celular van de a dos, para llegar rápido a la tabla; en la computadora, de a tres o los cuatro en fila. */
    protected function getColumns(): array
    {
        $perRow = count($this->getCachedStats()) === 4 ? 4 : 3;

        return ['default' => 2, '@3xl' => $perRow, '!@lg' => $perRow];
    }

    protected function getStats(): array
    {
        $today = AppointmentResource::getEloquentQuery()
            ->whereDate('starts_at', today())
            ->whereIn('status', AppointmentStatus::occupying())
            ->pluck('status');

        $done = $today->filter(fn (AppointmentStatus $s) => $s === AppointmentStatus::Completed)->count();
        $doneLabel = $done === 1 ? '1 atendida' : "{$done} atendidas";
        $waiting = $today->filter(fn (AppointmentStatus $s) => $s === AppointmentStatus::CheckedIn)->count();

        $unconfirmed = AppointmentResource::getEloquentQuery()
            ->where('status', AppointmentStatus::Pending)
            ->whereBetween('starts_at', [now(), today()->addDays(2)])
            ->count();

        $messages = WhatsappMessageResource::getEloquentQuery()->due()->count();

        $requests = RescheduleRequestResource::getEloquentQuery()
            ->where('status', RescheduleRequestStatus::Pending)
            ->count();

        return array_values(array_filter([
            Stat::make('Citas de hoy', $today->count())
                ->description($waiting > 0 ? "{$waiting} en sala · {$doneLabel}" : $doneLabel)
                ->icon('heroicon-o-calendar-days')
                ->url(AppointmentResource::getUrl('index', ['tab' => 'today'])),
            Stat::make('Sin confirmar (hoy y mañana)', $unconfirmed)
                ->description($unconfirmed > 0 ? 'Reenvía el aviso o llama' : 'Todo confirmado')
                ->color($unconfirmed > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-phone')
                ->url(AppointmentResource::getUrl('index', ['tab' => 'pending'])),
            Stat::make('Avisos por enviar', $messages)
                ->description($messages > 0 ? 'Listos para WhatsApp' : 'Nada pendiente')
                ->color($messages > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->url(WhatsappMessageResource::getUrl('index')),
            Stat::make('Solicitudes de cambio', $requests)
                ->description($requests > 0 ? 'Aprueba un horario con un clic' : 'Nada pendiente')
                ->color($requests > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-arrows-right-left')
                ->url(RescheduleRequestResource::getUrl('index')),
            $this->collectedToday(),
        ]));
    }

    /** Para recepción y administración: lo cobrado hoy y quién se fue sin pagar. */
    private function collectedToday(): ?Stat
    {
        if (! auth()->user()->can('viewAny', Payment::class)) {
            return null;
        }

        $collected = Payment::query()->whereNull('voided_at')->whereDate('paid_at', today())->sum('amount');
        $owing = Patient::query()
            ->owing()
            ->whereHas('appointments', fn (Builder $query) => $query->whereDate('starts_at', today())->where('status', AppointmentStatus::Completed))
            ->count();

        // El monto no entra en media tarjeta del celular: va a lo ancho.
        return Stat::make('Cobrado hoy', Money::format($collected))
            ->columnSpan(['default' => 2, '@3xl' => 1, '!@lg' => 1])
            ->description(match ($owing) {
                0 => 'Nadie se fue debiendo',
                1 => '1 paciente atendido hoy debe',
                default => "{$owing} pacientes atendidos hoy deben",
            })
            ->color($owing > 0 ? 'warning' : 'success')
            ->icon('heroicon-o-banknotes')
            ->url($owing > 0 ? AppointmentResource::getUrl('index', ['tab' => 'today']) : PaymentResource::getUrl('index'));
    }
}
