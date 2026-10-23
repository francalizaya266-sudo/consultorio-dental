<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatus;
use App\Enums\RescheduleRequestStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Números del día. Cada uno lleva a la lista donde se resuelve. */
class TodayStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $today = AppointmentResource::getEloquentQuery()
            ->whereDate('starts_at', today())
            ->whereIn('status', AppointmentStatus::occupying())
            ->pluck('status');

        $done = $today->filter(fn (AppointmentStatus $s) => $s === AppointmentStatus::Completed)->count();
        $waiting = $today->filter(fn (AppointmentStatus $s) => $s === AppointmentStatus::CheckedIn)->count();

        $unconfirmed = AppointmentResource::getEloquentQuery()
            ->where('status', AppointmentStatus::Pending)
            ->whereBetween('starts_at', [now(), today()->addDays(2)])
            ->count();

        $messages = WhatsappMessageResource::getEloquentQuery()->due()->count();

        $requests = RescheduleRequestResource::getEloquentQuery()
            ->where('status', RescheduleRequestStatus::Pending)
            ->count();

        return [
            Stat::make('Citas de hoy', $today->count())
                ->description($waiting > 0 ? "{$waiting} en sala · {$done} atendidas" : "{$done} atendidas")
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
        ];
    }
}
