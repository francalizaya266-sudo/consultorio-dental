<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageAuditLogs extends ManageRecords
{
    protected static string $resource = AuditLogResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Quién cambió qué y cuándo en Configuración y en la página web. Las contraseñas nunca se guardan aquí: solo queda que se cambiaron.';
    }
}
