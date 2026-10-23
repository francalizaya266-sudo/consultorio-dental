<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('respaldo:restaurar {archivo? : Nombre de la copia, por ejemplo respaldo_2026-10-23_030000.sql.gz (sin nombre, la más reciente)} {--force : No preguntar}')]
#[Description('Reemplaza toda la base de datos por una copia de seguridad')]
class RestoreBackup extends Command
{
    public function handle(DatabaseBackup $backup): int
    {
        $file = $this->argument('archivo') ?? ($backup->list()[0]['file'] ?? null);

        if ($file === null || ! DatabaseBackup::isBackupName(basename($file))) {
            $this->error('No hay ninguna copia con ese nombre en storage/app/private/'.DatabaseBackup::DIRECTORY.'.');

            return self::FAILURE;
        }

        $name = DatabaseBackup::DIRECTORY.'/'.basename($file);

        if (! $this->option('force') && ! $this->confirm("Se reemplazará TODA la base de datos por la copia {$file}. Lo registrado después de esa copia se pierde. ¿Seguir?")) {
            $this->line('No se cambió nada.');

            return self::FAILURE;
        }

        $statements = $backup->restore($name);

        $this->info("Base de datos restaurada desde {$file} ({$statements} sentencias).");

        return self::SUCCESS;
    }
}
