<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Backup\DatabaseBackup;
use App\Services\Backup\FilesBackup;
use App\Services\Backup\OffsiteCopies;
use App\Support\Dates;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

/**
 * Las copias de seguridad, para bajarlas y guardarlas fuera del hosting: la de los datos, que se crea
 * sola cada día, y las radiografías, fotos, documentos y firmas, que se bajan en archivos .zip.
 * Tienen datos clínicos: solo el administrador que es odontólogo las ve (Ley N.º 29733).
 */
class Backups extends Page
{
    protected string $view = 'filament.pages.backups';

    protected static ?string $title = 'Copias de seguridad';

    protected static ?string $slug = 'copias-de-seguridad';

    protected static ?string $navigationParentItem = 'Configuración';

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
        $service = app(DatabaseBackup::class);
        $backups = collect($service->list())->map(fn (array $backup) => [
            'file' => $backup['file'],
            'date' => ucfirst($backup['created_at']->translatedFormat('l j \d\e F \d\e Y')).', '.Dates::time($backup['created_at']),
            'ago' => $backup['created_at']->diffForHumans(),
            'size' => Number::fileSize($backup['size'], precision: 1),
            'url' => Filament::getPanel('admin')->route('backups.download', ['file' => $backup['file']]),
        ]);

        $copies = app(OffsiteCopies::class);
        $files = app(FilesBackup::class);
        $databaseDownload = $copies->lastDatabaseDownload();
        $lastFiles = $files->last();

        return [
            'backups' => $backups,
            'stale' => $service->isStale(),
            'keep' => (int) config('consultorio.backups.keep'),
            'due' => $copies->due(),
            'databaseDownloaded' => $databaseDownload ? self::when($databaseDownload['at'], $databaseDownload['by']) : null,
            'pending' => self::batch(FilesBackup::PENDING, $files->pending()),
            'last' => $lastFiles ? [...self::batch(FilesBackup::LAST, $lastFiles), 'when' => self::when($lastFiles['at'], $lastFiles['by'])] : null,
            // Mientras no se haya bajado nada, "lo nuevo" ya es todo.
            'everything' => $lastFiles ? self::batch(FilesBackup::EVERYTHING, $files->everything()) : null,
        ];
    }

    /**
     * @param  array{files: int, bytes: int, parts: list<array{number: int, files: int, bytes: int, done: bool}>}  $batch
     * @return array{summary: string, parts: list<array{number: int, of: int, summary: string, done: bool, url: string}>}
     */
    private static function batch(string $which, array $batch): array
    {
        return [
            'summary' => self::amount($batch['files'], $batch['bytes']),
            'parts' => array_map(fn (array $part) => [
                'number' => $part['number'],
                'of' => count($batch['parts']),
                'summary' => self::amount($part['files'], $part['bytes']),
                'done' => $part['done'],
                'url' => Filament::getPanel('admin')->route('backups.files.download', ['batch' => $which, 'part' => $part['number']]),
            ], $batch['parts']),
        ];
    }

    private static function amount(int $files, int $bytes): string
    {
        return ($files === 1 ? '1 archivo' : Number::format($files).' archivos').' · '.Number::fileSize($bytes, precision: 1);
    }

    private static function when(CarbonImmutable $at, ?string $by): string
    {
        return $at->diffForHumans().', el '.$at->translatedFormat('l j \d\e F').($by ? ', por '.$by : '');
    }
}
