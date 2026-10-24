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
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Números del día en una franja pequeña, para que la lista de citas quede a la vista.
 * Cada uno lleva a la lista donde se resuelve. Se actualiza solo cada 30 s.
 */
class TodayStats extends Widget
{
    protected static ?int $sort = 1;

    protected string $view = 'filament.widgets.today-stats';

    protected int|string|array $columnSpan = 'full';

    /** @return array{stats: list<array{label: string, value: string|int, hint: string, color: string, icon: string, url: string}>} */
    protected function getViewData(): array
    {
        return ['stats' => $this->stats()];
    }

    /** @return list<array{label: string, value: string|int, hint: string, color: string, icon: string, url: string}> */
    private function stats(): array
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
            [
                'label' => 'Citas de hoy',
                'value' => $today->count(),
                'hint' => $waiting > 0 ? "{$waiting} en sala · {$doneLabel}" : $doneLabel,
                'color' => 'primary',
                'icon' => 'heroicon-o-calendar-days',
                'url' => AppointmentResource::getUrl('index', ['tab' => 'today']),
            ],
            [
                'label' => 'Sin confirmar (hoy y mañana)',
                'value' => $unconfirmed,
                'hint' => $unconfirmed > 0 ? 'Reenvía el aviso o llama' : 'Todo confirmado',
                'color' => $unconfirmed > 0 ? 'warning' : 'success',
                'icon' => 'heroicon-o-phone',
                'url' => AppointmentResource::getUrl('index', ['tab' => 'pending']),
            ],
            [
                'label' => 'Avisos por enviar',
                'value' => $messages,
                'hint' => $messages > 0 ? 'Listos para WhatsApp' : 'Nada pendiente',
                'color' => $messages > 0 ? 'warning' : 'success',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'url' => WhatsappMessageResource::getUrl('index'),
            ],
            [
                'label' => 'Solicitudes de cambio',
                'value' => $requests,
                'hint' => $requests > 0 ? 'Aprueba un horario con un clic' : 'Nada pendiente',
                'color' => $requests > 0 ? 'danger' : 'success',
                'icon' => 'heroicon-o-arrows-right-left',
                'url' => RescheduleRequestResource::getUrl('index'),
            ],
            $this->collectedToday(),
        ]));
    }

    /**
     * Para recepción y administración: lo cobrado hoy y quién se fue sin pagar.
     *
     * @return array{label: string, value: string, hint: string, color: string, icon: string, url: string}|null
     */
    private function collectedToday(): ?array
    {
        if (! auth()->user()->can('viewAny', Payment::class)) {
            return null;
        }

        $collected = Payment::query()->whereNull('voided_at')->whereDate('paid_at', today())->sum('amount');
        $owing = Patient::query()
            ->owing()
            ->whereHas('appointments', fn (Builder $query) => $query->whereDate('starts_at', today())->where('status', AppointmentStatus::Completed))
            ->count();

        return [
            'label' => 'Cobrado hoy',
            'value' => Money::format($collected),
            'hint' => match ($owing) {
                0 => 'Nadie se fue debiendo',
                1 => '1 paciente atendido hoy debe',
                default => "{$owing} pacientes atendidos hoy deben",
            },
            'color' => $owing > 0 ? 'warning' : 'success',
            'icon' => 'heroicon-o-banknotes',
            'url' => $owing > 0 ? AppointmentResource::getUrl('index', ['tab' => 'today']) : PaymentResource::getUrl('index'),
        ];
    }
}
