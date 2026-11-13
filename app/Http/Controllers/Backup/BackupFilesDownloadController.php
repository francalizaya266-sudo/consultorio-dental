<?php

namespace App\Http\Controllers\Backup;

use App\Filament\Pages\Backups;
use App\Http\Controllers\Controller;
use App\Services\Backup\FilesBackup;
use App\Services\Backup\OffsiteCopies;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Baja en un .zip las radiografías, fotos, documentos y firmas: solo quien puede descargar copias. Queda anotado quién lo bajó. */
class BackupFilesDownloadController extends Controller
{
    public function __invoke(string $batch, int $part, FilesBackup $files, OffsiteCopies $copies): BinaryFileResponse
    {
        abort_unless(Backups::canDownload(), 403);

        try {
            $zip = $files->download($batch, $part, auth()->user());
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $copies->note(OffsiteCopies::DOWNLOADED, $zip['name']);

        return response()->download($zip['path'], $zip['name'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
