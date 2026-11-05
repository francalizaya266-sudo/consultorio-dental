<?php

namespace App\Filament\Support;

use App\Filament\Pages\Agenda;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;

/**
 * La barra fija de abajo en el celular: lo que más se toca, a un pulgar. Al centro, "Nueva cita".
 * Cada botón aparece solo si la persona puede entrar ahí (el doctor ve Avisos en vez de Caja).
 */
final class MobileBar
{
    /** @return list<array{label: string, icon: string, url: string, active: bool, primary: bool}> */
    public static function items(): array
    {
        $money = PaymentResource::canViewAny();

        return array_values(array_filter([
            self::item('Hoy', 'heroicon-o-home', Dashboard::getUrl(), request()->routeIs(Dashboard::getRouteName())),
            Agenda::canAccess()
                ? self::item('Agenda', 'heroicon-o-calendar', Agenda::getUrl(), request()->routeIs(Agenda::getRouteName(), AppointmentResource::getRouteBaseName().'.index'))
                : null,
            AppointmentResource::canCreate()
                ? self::item('Nueva cita', 'heroicon-o-plus', AppointmentResource::getUrl('create'), request()->routeIs(AppointmentResource::getRouteBaseName().'.create'), primary: true)
                : null,
            PatientResource::canViewAny()
                ? self::item('Pacientes', 'heroicon-o-users', PatientResource::getUrl('index'), request()->routeIs(PatientResource::getRouteBaseName().'.*'))
                : null,
            match (true) {
                $money => self::item('Caja', 'heroicon-o-banknotes', PaymentResource::getUrl('index'), request()->routeIs(PaymentResource::getRouteBaseName().'.*')),
                WhatsappMessageResource::canViewAny() => self::item('Avisos', 'heroicon-o-chat-bubble-left-right', WhatsappMessageResource::getUrl('index'), request()->routeIs(WhatsappMessageResource::getRouteBaseName().'.*')),
                default => null,
            },
        ]));
    }

    /** @return array{label: string, icon: string, url: string, active: bool, primary: bool} */
    private static function item(string $label, string $icon, string $url, bool $active, bool $primary = false): array
    {
        return ['label' => $label, 'icon' => $icon, 'url' => $url, 'active' => $active, 'primary' => $primary];
    }
}
