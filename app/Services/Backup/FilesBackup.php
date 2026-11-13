<?php

namespace App\Services\Backup;

use App\Models\Setting;
use App\Models\User;
use App\Services\Clinical\ClinicalFileService;
use App\Services\Web\PublicImages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Copia de lo que no va en la base de datos: radiografías, fotos, documentos y firmas de los pacientes,
 * y las fotos de la web. Se baja desde el panel en archivos .zip, por tandas: cada tanda trae lo nuevo
 * desde la anterior y, si pesa mucho, sale en partes para que el hosting no corte la descarga.
 */
final class FilesBackup
{
    /** Lo nuevo desde la última descarga. */
    public const PENDING = 'nuevos';

    /** La última tanda que se terminó de bajar, por si esa descarga se perdió. */
    public const LAST = 'ultima';

    /** Todo desde el principio: para una computadora nueva. */
    public const EVERYTHING = 'todos';

    public const BATCHES = [self::PENDING, self::LAST, self::EVERYTHING];

    /** Dónde se anota qué se bajó ya. */
    private const SETTING = 'backups.files';

    /** Recorrer todas las carpetas en cada visita a "Hoy" sería lento: el recordatorio se calcula cada tanto. */
    private const OLDEST_PENDING_CACHE = 'backups.files.oldest_pending';

    private const OLDEST_PENDING_CACHE_MINUTES = 15;

    /** Carpeta de cada disco que se copia y con qué nombre queda dentro del .zip. */
    private const SOURCES = [
        ['disk' => ClinicalFileService::DISK, 'directory' => 'clinica', 'folder' => 'clinica'],
        ['disk' => PublicImages::DISK, 'directory' => '', 'folder' => 'web'],
    ];

    private const README = "Archivos del consultorio: radiografías, fotos, documentos y firmas de los pacientes, y las fotos de la web.\r\n\r\n"
        ."Cada descarga trae solo lo nuevo desde la anterior: para recuperar todo hacen falta todas.\r\n"
        ."Para recuperarlos, la carpeta \"clinica\" va dentro de storage/app/private/ y la carpeta \"web\" es public/media/.\r\n";

    /**
     * Lo que falta bajar: la tanda que quedó a medias o, si no hay, todo lo nuevo hasta este momento.
     *
     * @return array{files: int, bytes: int, parts: list<array{number: int, files: int, bytes: int, done: bool}>}
     */
    public function pending(): array
    {
        $state = $this->state();

        if ($state['batch'] !== null) {
            return $this->describe($state['batch']);
        }

        return $this->describe($this->newBatch($state['after']));
    }

    /**
     * La última tanda que se terminó de bajar.
     *
     * @return array{files: int, bytes: int, parts: list<array{number: int, files: int, bytes: int, done: bool}>, at: CarbonImmutable, by: string|null}|null
     */
    public function last(): ?array
    {
        $last = $this->state()['last'];

        if ($last === null) {
            return null;
        }

        return [
            ...$this->describe($last),
            'at' => CarbonImmutable::createFromTimestamp($last['at'], config('app.timezone')),
            'by' => $last['by'],
        ];
    }

    /**
     * Todos los archivos, sin importar qué se bajó antes.
     *
     * @return array{files: int, bytes: int, parts: list<array{number: int, files: int, bytes: int, done: bool}>}
     */
    public function everything(): array
    {
        return $this->describe($this->newBatch(null));
    }

    /** El archivo más antiguo que todavía no se bajó: desde ahí corre la semana del recordatorio. */
    public function oldestPendingAt(): ?CarbonImmutable
    {
        $timestamp = Cache::remember(self::OLDEST_PENDING_CACHE, now()->addMinutes(self::OLDEST_PENDING_CACHE_MINUTES), function (): array {
            $state = $this->state();
            $batch = $state['batch'] ?? $this->newBatch($state['after']);

            foreach ($this->partsOf($batch) as $index => $files) {
                if (! in_array($index + 1, $batch['done'] ?? [], true)) {
                    return [(int) Str::before($files[0]['key'], '|')];
                }
            }

            return [null];
        })[0];

        return $timestamp === null ? null : CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone'));
    }

    /**
     * Arma el .zip de una parte y lo deja en una carpeta temporal: quien lo envía lo borra al terminar.
     * Si es de lo nuevo, anota que esa parte ya se bajó; cuando están todas, lo siguiente nuevo empieza desde ahí.
     *
     * @return array{path: string, name: string}
     */
    public function download(string $which, int $part, ?User $by): array
    {
        $batch = $this->batch($which);
        $files = $this->partsOf($batch)[$part - 1] ?? [];

        if ($files === []) {
            throw new InvalidArgumentException('Esa parte no tiene archivos.');
        }

        $directory = Storage::disk('local')->path(DatabaseBackup::DIRECTORY.'/tmp');
        File::ensureDirectoryExists($directory);
        $this->deleteAbandoned($directory);

        $path = $directory.'/'.Str::uuid().'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo .zip de la copia.');
        }

        $zip->addFromString('LEEME.txt', self::README);

        foreach ($files as $file) {
            $zip->addFile($file['path'], $file['name']);
            // Las imágenes ya vienen comprimidas: volver a comprimirlas solo gasta tiempo del hosting.
            $zip->setCompressionName($file['name'], ZipArchive::CM_STORE);
        }

        if (! $zip->close()) {
            throw new RuntimeException('No se pudo crear el archivo .zip de la copia.');
        }

        if ($which === self::PENDING) {
            $this->markDownloaded($batch, $part, $by);
        }

        return ['path' => $path, 'name' => $this->zipName($which, $batch, $part)];
    }

    /** @param  array{after: string|null, until: int, ends: list<string>, files: int, bytes: int, done?: list<int>}  $batch */
    private function markDownloaded(array $batch, int $part, ?User $by): void
    {
        $batch['done'] = array_values(array_unique([...$batch['done'] ?? [], $part]));
        $finished = count($batch['done']) === count($batch['ends']);

        Setting::set(self::SETTING, $finished
            // Lo siguiente nuevo empieza después del último archivo que salió en esta tanda.
            ? ['after' => $batch['ends'][count($batch['ends']) - 1], 'batch' => null, 'last' => [...$batch, 'at' => now()->getTimestamp(), 'by' => $by?->name]]
            : [...$this->state(), 'batch' => $batch]);

        Cache::forget(self::OLDEST_PENDING_CACHE);
    }

    /**
     * La tanda con la que se arma el .zip.
     *
     * @return array{after: string|null, until: int, ends: list<string>, files: int, bytes: int, done?: list<int>}
     */
    private function batch(string $which): array
    {
        $state = $this->state();

        return match ($which) {
            self::PENDING => $state['batch'] ?? $this->newBatch($state['after']),
            self::LAST => $state['last'] ?? throw new InvalidArgumentException('Todavía no se ha bajado ninguna tanda.'),
            self::EVERYTHING => $this->newBatch(null),
            default => throw new InvalidArgumentException("No existe la tanda {$which}."),
        };
    }

    /**
     * Una tanda con lo que hay después del archivo $after y hasta el segundo anterior (lo de este mismo segundo
     * puede estar a medio escribir), partida para que ningún .zip pase del tamaño permitido.
     *
     * @return array{after: string|null, until: int, ends: list<string>, files: int, bytes: int}
     */
    private function newBatch(?string $after): array
    {
        $until = now()->getTimestamp() - 1;
        $files = $this->files($after, $until);
        $limit = max(1, (int) config('consultorio.backups.files_part_mb')) * 1024 * 1024;
        $ends = [];
        $bytesInPart = 0;

        foreach ($files as $index => $file) {
            if ($index > 0 && $bytesInPart + $file['size'] > $limit) {
                $ends[] = $files[$index - 1]['key'];
                $bytesInPart = 0;
            }

            $bytesInPart += $file['size'];
        }

        if ($files !== []) {
            $ends[] = $files[count($files) - 1]['key'];
        }

        return ['after' => $after, 'until' => $until, 'ends' => $ends, 'files' => count($files), 'bytes' => array_sum(array_column($files, 'size'))];
    }

    /**
     * La tanda con cada parte contada, para mostrarla.
     *
     * @param  array{after: string|null, until: int, ends: list<string>, files: int, bytes: int, done?: list<int>}  $batch
     * @return array{files: int, bytes: int, parts: list<array{number: int, files: int, bytes: int, done: bool}>}
     */
    private function describe(array $batch): array
    {
        $parts = [];

        foreach ($this->partsOf($batch) as $index => $files) {
            $parts[] = [
                'number' => $index + 1,
                'files' => count($files),
                'bytes' => array_sum(array_column($files, 'size')),
                'done' => in_array($index + 1, $batch['done'] ?? [], true),
            ];
        }

        return ['files' => $batch['files'], 'bytes' => $batch['bytes'], 'parts' => $parts];
    }

    /**
     * Los archivos de cada parte. Una parte termina en el archivo que se anotó al armar la tanda,
     * así que si después se borra alguno, los demás no cambian de parte.
     *
     * @param  array{after: string|null, until: int, ends: list<string>}  $batch
     * @return list<list<array{key: string, path: string, name: string, size: int}>>
     */
    private function partsOf(array $batch): array
    {
        $parts = array_fill(0, count($batch['ends']), []);
        $index = 0;

        foreach ($this->files($batch['after'], $batch['until']) as $file) {
            while ($index < count($batch['ends']) && strcmp($file['key'], $batch['ends'][$index]) > 0) {
                $index++;
            }

            if ($index < count($batch['ends'])) {
                $parts[$index][] = $file;
            }
        }

        return $parts;
    }

    /**
     * Los archivos que van después del archivo $after y se guardaron hasta $until, del más antiguo al más nuevo.
     * Cada uno lleva una clave con su fecha y su nombre: ordena por antigüedad y marca dónde termina cada parte
     * y hasta dónde se bajó.
     *
     * @return list<array{key: string, path: string, name: string, size: int}>
     */
    private function files(?string $after, int $until): array
    {
        $files = [];

        foreach (self::SOURCES as $source) {
            $root = Storage::disk($source['disk'])->path($source['directory']);

            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $name = $source['folder'].'/'.str_replace('\\', '/', $file->getRelativePathname());
                $key = sprintf('%011d|%s', $file->getMTime(), $name);

                if ($file->getMTime() > $until || ($after !== null && strcmp($key, $after) <= 0)) {
                    continue;
                }

                $files[] = ['key' => $key, 'path' => $file->getPathname(), 'name' => $name, 'size' => $file->getSize()];
            }
        }

        usort($files, fn (array $a, array $b) => strcmp($a['key'], $b['key']));

        return $files;
    }

    /** @param  array{until: int, ends: list<string>}  $batch */
    private function zipName(string $which, array $batch, int $part): string
    {
        $date = CarbonImmutable::createFromTimestamp($batch['until'], config('app.timezone'))->format('Y-m-d');
        $total = count($batch['ends']);

        return 'archivos_'.($which === self::EVERYTHING ? 'todos_' : '').$date.($total > 1 ? "_parte-{$part}-de-{$total}" : '').'.zip';
    }

    /** Un .zip que quedó de una descarga cortada: después de un día ya nadie lo está bajando. */
    private function deleteAbandoned(string $directory): void
    {
        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < time() - 86400) {
                File::delete($file->getPathname());
            }
        }
    }

    /**
     * @return array{
     *     after: string|null,
     *     batch: array{after: string|null, until: int, ends: list<string>, files: int, bytes: int, done: list<int>}|null,
     *     last: array{after: string|null, until: int, ends: list<string>, files: int, bytes: int, done: list<int>, at: int, by: string|null}|null,
     * }
     */
    private function state(): array
    {
        return [...['after' => null, 'batch' => null, 'last' => null], ...(array) Setting::get(self::SETTING, [])];
    }
}
