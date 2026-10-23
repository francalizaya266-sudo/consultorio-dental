<?php

namespace App\Services\Web;

use App\Support\Images;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos que se muestran en la web: se achican a 1600 px y se guardan en JPEG sin datos
 * EXIF (la ubicación de la foto no debe quedar publicada).
 */
final class PublicImages
{
    public const DISK = 'web';

    public const MAX_SIDE = 1600;

    /** Ruta guardada en el disco "web", o null si el archivo no es una imagen legible. */
    public function store(UploadedFile $upload, string $folder): ?string
    {
        $image = Images::read($upload->getRealPath());

        if ($image === null) {
            return null;
        }

        $path = trim($folder, '/').'/'.Str::uuid().'.jpg';
        Storage::disk(self::DISK)->put($path, Images::jpeg($image, self::MAX_SIDE));

        return $path;
    }

    public static function url(?string $path): ?string
    {
        return filled($path) ? Storage::disk(self::DISK)->url($path) : null;
    }
}
