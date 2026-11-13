<?php

namespace App\Services\Backup;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Una copia que se queda en el hosting no sirve si el hosting falla. Aquí se anota cuándo se bajó
 * la de los datos y se avisa cuando pasa una semana sin bajarla o sin bajar los archivos nuevos.
 */
final class OffsiteCopies
{
    public const REMIND_AFTER_DAYS = 7;

    /** Como queda en el historial de cambios que alguien bajó o borró una copia. */
    public const DOWNLOADED = 'backup_downloaded';

    public const DELETED = 'backup_deleted';

    private const SETTING = 'backups.database_download';

    public function __construct(private DatabaseBackup $database, private FilesBackup $files) {}

    public function recordDatabaseDownload(?User $by, string $file): void
    {
        Setting::set(self::SETTING, ['at' => now()->getTimestamp(), 'by' => $by?->name]);

        $this->note(self::DOWNLOADED, $file);
    }

    /** Una copia trae los datos de todos los pacientes: en el historial de cambios queda quién bajó o borró cada una. */
    public function note(string $event, string $file): void
    {
        Setting::noteEvent(self::SETTING, $event, ['file' => $file]);
    }

    /**
     * La última vez que se bajó cada copia de los datos.
     *
     * @param  list<string>  $files
     * @return array<string, array{at: CarbonImmutable, by: string|null}>
     */
    public function lastDownloadOfEach(array $files): array
    {
        return AuditLog::query()
            ->where('event', self::DOWNLOADED)
            ->whereIn('new_values->file', $files)
            ->with('user')
            ->oldest('id')
            ->get()
            ->mapWithKeys(fn (AuditLog $log) => [$log->new_values['file'] => ['at' => $log->created_at->toImmutable(), 'by' => $log->user?->name]])
            ->all();
    }

    /** @return array{at: CarbonImmutable, by: string|null}|null */
    public function lastDatabaseDownload(): ?array
    {
        $last = Setting::get(self::SETTING);

        return $last === null ? null : [
            'at' => CarbonImmutable::createFromTimestamp($last['at'], config('app.timezone')),
            'by' => $last['by'],
        ];
    }

    /** Lo que toca bajar esta semana, en una frase sin punto final, o null si todo está al día. */
    public function due(): ?string
    {
        $deadline = now()->subDays(self::REMIND_AFTER_DAYS);
        $last = $this->lastDatabaseDownload();
        $oldestFile = $this->files->oldestPendingAt();

        $due = array_filter([
            match (true) {
                // Un sistema recién instalado tiene su primera semana antes del primer aviso.
                $last === null => $this->database->oldestAt()?->lt($deadline) ? 'todavía no se ha descargado ninguna copia de los datos' : null,
                $last['at']->lt($deadline) => 'la última descarga de los datos fue '.$last['at']->diffForHumans(),
                default => null,
            },
            $oldestFile?->lt($deadline) ? 'hay radiografías, fotos o firmas sin descargar desde '.$oldestFile->diffForHumans() : null,
        ]);

        return $due === [] ? null : ucfirst(implode(' y ', $due));
    }
}
