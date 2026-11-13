<?php

namespace App\Services\Backup;

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

    private const SETTING = 'backups.database_download';

    public function __construct(private DatabaseBackup $database, private FilesBackup $files) {}

    public function recordDatabaseDownload(?User $by): void
    {
        Setting::set(self::SETTING, ['at' => now()->getTimestamp(), 'by' => $by?->name]);
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
