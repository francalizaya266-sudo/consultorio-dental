<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Backups;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Backup\DatabaseBackup;
use Filament\Widgets\Widget;

/**
 * Solo aparece si algo anda mal y nadie lo vería: las copias de seguridad dejaron de hacerse
 * (la tarea programada del hosting no corre) o la API de WhatsApp no pudo enviar avisos.
 */
class SystemHealth extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.system-health';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin() && self::issues() !== [];
    }

    /** @return array{issues: list<array{heading: string, description: string, url: string|null, link: string|null}>} */
    protected function getViewData(): array
    {
        return ['issues' => self::issues()];
    }

    /** @return list<array{heading: string, description: string, url: string|null, link: string|null}> */
    private static function issues(): array
    {
        $issues = [];
        $backups = app(DatabaseBackup::class);

        if ($backups->isStale()) {
            $latest = $backups->latestAt();
            $issues[] = [
                'heading' => $latest ? 'La última copia de seguridad es de '.$latest->diffForHumans() : 'Todavía no hay copias de seguridad',
                'description' => 'Las copias se hacen solas cada noche con la tarea programada (cron) del hosting. Revisa que esté activa.',
                'url' => Backups::canAccess() ? Backups::getUrl() : null,
                'link' => 'Ver copias',
            ];
        }

        $failed = WhatsappMessage::query()->failedRecently()->count();

        if ($failed > 0) {
            $issues[] = [
                'heading' => $failed === 1 ? '1 aviso de WhatsApp no se pudo enviar esta semana' : "{$failed} avisos de WhatsApp no se pudieron enviar esta semana",
                'description' => 'La API de WhatsApp los rechazó. Revisa el motivo en cada aviso y envíalos a mano si hace falta.',
                'url' => WhatsappMessageResource::getUrl('index', ['tab' => 'failed']),
                'link' => 'Ver avisos',
            ];
        }

        return $issues;
    }
}
