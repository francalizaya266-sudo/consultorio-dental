<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('respaldo:crear')]
#[Description('Guarda una copia de seguridad de la base de datos y borra las que pasaron el plazo')]
class CreateBackup extends Command
{
    public function handle(DatabaseBackup $backup): int
    {
        $name = $backup->create();

        $this->info("Copia guardada: storage/app/private/{$name}");

        return self::SUCCESS;
    }
}
