<?php

namespace App\Http\Controllers\Backup;

use App\Filament\Pages\Backups;
use App\Http\Controllers\Controller;
use App\Services\Backup\DatabaseBackup;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Baja una copia de seguridad: solo quien ve la página de copias y solo archivos de copia. */
class BackupDownloadController extends Controller
{
    public function __invoke(string $file): StreamedResponse
    {
        abort_unless(Backups::canAccess(), 403);
        abort_unless(DatabaseBackup::isBackupName($file), 404);

        $name = DatabaseBackup::DIRECTORY.'/'.$file;
        abort_unless(Storage::disk('local')->exists($name), 404);

        return Storage::disk('local')->download($name, $file, ['Content-Type' => 'application/gzip']);
    }
}
