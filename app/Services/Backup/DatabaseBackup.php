<?php

namespace App\Services\Backup;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copia de seguridad de la base de datos en un solo archivo .sql.gz, sin mysqldump (muchos hostings
 * compartidos no lo permiten). Se restaura con `php artisan respaldo:restaurar` o importándola en phpMyAdmin.
 * Las radiografías, fotos y firmas están en disco, no aquí: van en la copia de archivos del hosting.
 */
final class DatabaseBackup
{
    public const DIRECTORY = 'respaldos';

    /** Sesiones y caché se guardan vacías: se rehacen solas. */
    private const STRUCTURE_ONLY = ['sessions', 'cache', 'cache_locks'];

    private const ROWS_PER_INSERT = 100;

    private Connection $connection;

    public function __construct()
    {
        $this->connection = DB::connection();
    }

    /** Crea la copia, borra las que pasaron el plazo y devuelve el nombre del archivo. */
    public function create(): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);
        $name = self::DIRECTORY.'/respaldo_'.now()->format('Y-m-d_His').'.sql.gz';
        $path = $disk->path($name);
        // Se escribe aparte y se renombra al terminar: nunca queda una copia a medias con el nombre final.
        $partial = $path.'.part';
        $file = gzopen($partial, 'wb6');

        if ($file === false) {
            throw new RuntimeException('No se pudo crear el archivo de la copia de seguridad.');
        }

        $write = fn (string $sql) => gzwrite($file, $sql);
        $write('-- Copia de seguridad de '.Setting::get('clinic.name').' · '.now()->format('d/m/Y H:i')."\n");
        $write('-- Restaurar: php artisan respaldo:restaurar '.basename($name).", o importar en phpMyAdmin.\n\n");
        $write(implode('', $this->foreignKeyChecks(false))."\n");

        foreach ($this->tables() as $table) {
            $this->dumpTable($table, $write);
        }

        $write(implode('', $this->foreignKeyChecks(true)));
        gzclose($file);
        rename($partial, $path);

        $this->prune();

        return $name;
    }

    /**
     * Reemplaza toda la base de datos por la de la copia.
     *
     * @return int Sentencias ejecutadas
     */
    public function restore(string $name): int
    {
        $path = Storage::disk('local')->path($name);
        $file = is_file($path) ? gzopen($path, 'rb') : false;

        if ($file === false) {
            throw new RuntimeException("No se encontró la copia {$name}.");
        }

        $executed = 0;
        $statement = '';
        $quoted = false;
        $backslashEscapes = $this->usesBackslashEscapes();

        foreach ($this->foreignKeyChecks(false) as $sql) {
            $this->connection->unprepared($sql);
        }

        // Se separan las sentencias por ";" al final de línea, sin cortar los textos que traen ";" o saltos de línea.
        while (($line = gzgets($file)) !== false) {
            if (! $quoted && $statement === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }

            for ($i = 0, $length = strlen($line); $i < $length; $i++) {
                $char = $line[$i];

                if ($quoted && $backslashEscapes && $char === '\\') {
                    $i++;
                } elseif ($char === "'") {
                    $quoted = ! $quoted;
                }
            }

            $statement .= $line;

            if (! $quoted && str_ends_with(rtrim($line), ';')) {
                $this->connection->unprepared($statement);
                $executed++;
                $statement = '';
            }
        }

        gzclose($file);

        foreach ($this->foreignKeyChecks(true) as $sql) {
            $this->connection->unprepared($sql);
        }

        return $executed;
    }

    /**
     * Las copias guardadas, de la más nueva a la más antigua.
     *
     * @return list<array{name: string, file: string, size: int, created_at: CarbonImmutable}>
     */
    public function list(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIRECTORY))
            ->filter(fn (string $name) => self::isBackupName(basename($name)))
            ->sortDesc()
            ->values()
            ->map(fn (string $name) => [
                'name' => $name,
                'file' => basename($name),
                'size' => $disk->size($name),
                'created_at' => CarbonImmutable::createFromFormat('Y-m-d_His', substr(basename($name), 9, 17)),
            ])
            ->all();
    }

    /** Para no servir otra cosa que una copia: "respaldo_2026-10-23_030000.sql.gz". */
    public static function isBackupName(string $file): bool
    {
        return preg_match('/^respaldo_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/', $file) === 1;
    }

    private function prune(): void
    {
        $keep = max(1, (int) config('consultorio.backups.keep', 14));

        foreach (array_slice($this->list(), $keep) as $old) {
            Storage::disk('local')->delete($old['name']);
        }
    }

    /** @return list<string> Las tablas de esta base de datos (no las de otras bases del mismo hosting). */
    private function tables(): array
    {
        $schema = $this->connection->getDriverName() === 'sqlite' ? 'main' : $this->connection->getDatabaseName();

        return collect($this->connection->getSchemaBuilder()->getTableListing($schema, schemaQualified: false))->sort()->values()->all();
    }

    /** @param  callable(string): mixed  $write */
    private function dumpTable(string $table, callable $write): void
    {
        $wrapped = $this->connection->getQueryGrammar()->wrapTable($table);
        $write("-- {$table}\nDROP TABLE IF EXISTS {$wrapped};\n");

        foreach ($this->createStatements($table) as $sql) {
            $write(rtrim($sql, "; \n").";\n");
        }

        if (in_array($table, self::STRUCTURE_ONLY, true)) {
            $write("\n");

            return;
        }

        $columns = null;
        $values = [];
        $flush = function () use (&$values, &$columns, $wrapped, $write) {
            if ($values !== []) {
                $write("INSERT INTO {$wrapped} ({$columns}) VALUES\n".implode(",\n", $values).";\n");
                $values = [];
            }
        };

        foreach ($this->connection->table($table)->cursor() as $row) {
            $row = (array) $row;
            $columns ??= implode(', ', array_map(fn (string $column) => $this->connection->getQueryGrammar()->wrap($column), array_keys($row)));
            $values[] = '('.implode(', ', array_map($this->literal(...), $row)).')';

            if (count($values) === self::ROWS_PER_INSERT) {
                $flush();
            }
        }

        $flush();
        $write("\n");
    }

    /** @return list<string> */
    private function createStatements(string $table): array
    {
        return match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => [((array) $this->connection->selectOne('SHOW CREATE TABLE '.$this->connection->getQueryGrammar()->wrapTable($table)))['Create Table']],
            'sqlite' => $this->connection->table('sqlite_master')
                ->where('tbl_name', $table)
                ->whereNotNull('sql')
                // Primero la tabla, después sus índices.
                ->orderByRaw("type = 'table' desc")
                ->pluck('sql')
                ->all(),
            default => throw new RuntimeException('Copias de seguridad disponibles solo para MySQL, MariaDB y SQLite.'),
        };
    }

    private function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $this->connection->getPdo()->quote((string) $value),
        };
    }

    /** @return list<string> */
    private function foreignKeyChecks(bool $on): array
    {
        return match ($this->connection->getDriverName()) {
            'sqlite' => $on ? ["PRAGMA foreign_keys=ON;\n"] : ["PRAGMA foreign_keys=OFF;\n", "PRAGMA defer_foreign_keys=ON;\n"],
            default => ['SET FOREIGN_KEY_CHECKS='.($on ? 1 : 0).";\n"],
        };
    }

    /** MySQL escribe \' dentro de los textos; SQLite solo duplica la comilla (''), que se lee como cerrar y abrir. */
    private function usesBackslashEscapes(): bool
    {
        return in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
