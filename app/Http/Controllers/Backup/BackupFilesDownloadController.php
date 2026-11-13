<?php

namespace App\Http\Controllers\Backup;

use App\Filament\Pages\Backups;
use App\Http\Controllers\Controller;
use App\Services\Backup\FilesBackup;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Baja en un .zip las radiografías, fotos, documentos y firmas: solo quien ve la página de copias. */
class BackupFilesDownloadController extends Controller
{
    public function __invoke(string $batch, int $part, FilesBackup $files): BinaryFileResponse
    {
        abort_unless(Backups::canAccess(), 403);

        try {
            $zip = $files->download($batch, $part, auth()->user());
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return response()->download($zip['path'], $zip['name'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
