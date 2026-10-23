<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Backup\DatabaseBackup;
use App\Support\Dates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Las copias de seguridad diarias, para bajarlas y guardarlas fuera del hosting.
 * Tienen datos clínicos: solo el administrador que es odontólogo las ve (Ley N.º 29733).
 */
class Backups extends Page
{
    /** Si la última copia es más vieja que esto, el cron del hosting no está corriendo. */
    private const STALE_AFTER_HOURS = 36;

    protected string $view = 'filament.pages.backups';

    protected static ?string $title = 'Copias de seguridad';

    protected static ?string $slug = 'copias-de-seguridad';

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 11;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin() && $user->canSeeClinical();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Crear copia ahora')
                ->icon(Heroicon::OutlinedPlus)
                ->action(function (DatabaseBackup $backup) {
                    $backup->create();

                    Notification::make()->title('Copia creada')->success()->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $backups = collect(app(DatabaseBackup::class)->list())->map(fn (array $backup) => [
            'file' => $backup['file'],
            'date' => ucfirst($backup['created_at']->translatedFormat('l j \d\e F \d\e Y')).', '.Dates::time($backup['created_at']),
            'ago' => $backup['created_at']->diffForHumans(),
            'size' => Number::fileSize($backup['size'], precision: 1),
            'url' => Filament::getPanel('admin')->route('backups.download', ['file' => $backup['file']]),
        ]);
        $latest = app(DatabaseBackup::class)->list()[0]['created_at'] ?? null;

        return [
            'backups' => $backups,
            'stale' => $latest === null || $latest->lt(now()->subHours(self::STALE_AFTER_HOURS)),
            'keep' => (int) config('consultorio.backups.keep'),
        ];
    }
}
