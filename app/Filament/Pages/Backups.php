<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Filament\Concerns\HasSettingsMenuGroup;
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
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Number;

/**
 * Las copias de seguridad, para bajarlas y guardarlas fuera del hosting: la de los datos, que se crea
 * sola cada día, y las radiografías, fotos, documentos y firmas, que se bajan en archivos .zip.
 * Cualquier administrador ve si están al día, crea una y borra las viejas. Descargarlas es otra cosa: traen
 * datos clínicos, así que solo las baja el administrador que es odontólogo (Ley N.º 29733).
 */
class Backups extends Page
{
    use HasSettingsMenuGroup;

    protected string $view = 'filament.pages.backups';

    protected static ?string $title = 'Copias de seguridad';

    protected static ?string $slug = 'copias-de-seguridad';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    public static function settingsMenuGroup(): string
    {
        return 'Consultorio';
    }

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin();
    }

    /** Las copias traen la historia clínica de todos los pacientes: las baja solo quien puede verla. */
    public static function canDownload(): bool
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

    /** El tacho de cada copia vieja. La más reciente no lo tiene: es la que protege al consultorio hoy. */
    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Borrar esta copia')
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->color('gray')
            ->size(Size::Small)
            ->tooltip('Borrar esta copia')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Borrar la copia del '.self::dayOf((string) ($arguments['file'] ?? '')))
            ->modalDescription('Se borra del sistema y no se puede recuperar. Si ya la descargaste, la que guardaste fuera sigue sirviendo.')
            ->modalSubmitActionLabel('Borrar copia')
            ->action(function (array $arguments, DatabaseBackup $backup, OffsiteCopies $copies): void {
                $file = (string) ($arguments['file'] ?? '');

                if (! $backup->delete($file)) {
                    Notification::make()->title('Esa copia no se puede borrar')->body('La copia más reciente se queda siempre.')->danger()->send();

                    return;
                }

                $copies->note(OffsiteCopies::DELETED, $file);

                Notification::make()->title('Copia borrada')->success()->send();
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service = app(DatabaseBackup::class);
        $copies = app(OffsiteCopies::class);
        $list = $service->list();
        $downloads = $copies->lastDownloadOfEach(array_column($list, 'file'));
        $backups = collect($list)->map(fn (array $backup) => [
            'file' => $backup['file'],
            'date' => ucfirst($backup['created_at']->translatedFormat('l j \d\e F \d\e Y')).', '.Dates::time($backup['created_at']),
            'ago' => $backup['created_at']->diffForHumans(),
            'size' => Number::fileSize($backup['size'], precision: 1),
            'downloaded' => isset($downloads[$backup['file']])
                ? 'La descargó '.($downloads[$backup['file']]['by'] ?? 'alguien').' '.$downloads[$backup['file']]['at']->diffForHumans()
                : null,
            'url' => Filament::getPanel('admin')->route('backups.download', ['file' => $backup['file']]),
        ]);

        $files = app(FilesBackup::class);
        $databaseDownload = $copies->lastDatabaseDownload();
        $lastFiles = $files->last();

        return [
            'canDownload' => self::canDownload(),
            'whoDownloads' => self::whoDownloads(),
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

    /** A quién pedirle la descarga: "Ana Torres" o "Ana Torres y Luis Paz"; null si hoy nadie puede bajarlas. */
    private static function whoDownloads(): ?string
    {
        $names = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_dentist', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $names === [] ? null : Arr::join($names, ', ', ' y ');
    }

    /** "viernes 23 de octubre, 3:00 a. m.", del nombre del archivo de la copia. */
    private static function dayOf(string $file): string
    {
        if (! DatabaseBackup::isBackupName($file)) {
            return 'día elegido';
        }

        $created = CarbonImmutable::createFromFormat('Y-m-d_His', substr($file, 9, 17));

        return $created->translatedFormat('l j \d\e F').', '.Dates::time($created);
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
