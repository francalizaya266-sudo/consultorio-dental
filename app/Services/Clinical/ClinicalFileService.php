<?php

namespace App\Services\Clinical;

use App\Enums\ClinicalFileType;
use App\Models\ClinicalFile;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonInterface;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Radiografías, fotos y documentos en el disco privado (nunca públicos). Las imágenes se
 * comprimen al subirlas para cuidar el espacio del hosting: lado mayor de 2000 px en JPEG,
 * más una miniatura para las listas.
 */
final class ClinicalFileService
{
    public const DISK = 'local';

    public const MAX_SIDE = 2000;

    public const THUMB_SIDE = 360;

    public const QUALITY = 82;

    /** Tipos que se aceptan al subir: imágenes y PDF. */
    public const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    public function store(
        Patient $patient,
        UploadedFile $upload,
        ClinicalFileType $type,
        User $by,
        ?Consultation $consultation = null,
        ?string $title = null,
        ?CarbonInterface $takenAt = null,
    ): ClinicalFile {
        $base = 'clinica/'.$patient->getKey().'/'.Str::uuid();
        $mime = (string) $upload->getMimeType();
        $compressed = str_starts_with($mime, 'image/') ? $this->compress($upload->getRealPath()) : null;

        if ($compressed) {
            [$image, $thumbnail] = $compressed;
            $path = $base.'.jpg';
            Storage::disk(self::DISK)->put($path, $image);
            Storage::disk(self::DISK)->put($this->thumbnailPathFor($path), $thumbnail);
            $mime = 'image/jpeg';
            $size = strlen($image);
        } else {
            $path = $base.'.'.($upload->guessExtension() ?: $upload->getClientOriginalExtension() ?: 'bin');
            Storage::disk(self::DISK)->put($path, (string) file_get_contents($upload->getRealPath()));
            $size = (int) $upload->getSize();
        }

        return ClinicalFile::create([
            'patient_id' => $patient->getKey(),
            'consultation_id' => $consultation?->getKey(),
            'type' => $type,
            'title' => filled($title) ? $title : pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME),
            'disk' => self::DISK,
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'taken_at' => $takenAt ?? now(),
            'uploaded_by_user_id' => $by->getKey(),
        ]);
    }

    /** Miniatura de una imagen (o null si es un PDF u otro documento). */
    public function thumbnailPath(ClinicalFile $file): ?string
    {
        $thumbnail = $this->thumbnailPathFor($file->path);

        return Storage::disk($file->disk)->exists($thumbnail) ? $thumbnail : null;
    }

    /** Espacio que ocupan los archivos clínicos (para vigilar el límite del hosting). */
    public function usedBytes(?Patient $patient = null): int
    {
        return (int) ClinicalFile::query()
            ->when($patient, fn ($query) => $query->whereBelongsTo($patient))
            ->sum('size_bytes');
    }

    public static function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => number_format($bytes / 1024 ** 3, 1).' GB',
            $bytes >= 1024 ** 2 => number_format($bytes / 1024 ** 2, 1).' MB',
            default => max(1, (int) round($bytes / 1024)).' KB',
        };
    }

    /**
     * Imagen enderezada (según la cámara), achicada y en JPEG, más su miniatura.
     * Null si GD no puede leerla: entonces se guarda tal cual.
     *
     * @return array{0: string, 1: string}|null
     */
    public function compress(string $path): ?array
    {
        $contents = @file_get_contents($path);
        $source = $contents !== false ? @imagecreatefromstring($contents) : false;

        if (! $source instanceof GdImage) {
            return null;
        }

        $source = $this->orient($source, $path);

        return [$this->jpeg($source, self::MAX_SIDE), $this->jpeg($source, self::THUMB_SIDE)];
    }

    private function jpeg(GdImage $source, int $maxSide): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // Fondo blanco: las radiografías en PNG suelen traer transparencias.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    /** Las fotos del celular guardan la orientación aparte (EXIF): se aplica para que no salgan echadas. */
    private function orient(GdImage $image, string $path): GdImage
    {
        $orientation = function_exists('exif_read_data') ? (int) (@exif_read_data($path)['Orientation'] ?? 1) : 1;

        return match ($orientation) {
            2 => tap($image, fn ($i) => imageflip($i, IMG_FLIP_HORIZONTAL)),
            3 => imagerotate($image, 180, 0),
            4 => tap($image, fn ($i) => imageflip($i, IMG_FLIP_VERTICAL)),
            5 => tap(imagerotate($image, -90, 0), fn ($i) => imageflip($i, IMG_FLIP_HORIZONTAL)),
            6 => imagerotate($image, -90, 0),
            7 => tap(imagerotate($image, 90, 0), fn ($i) => imageflip($i, IMG_FLIP_HORIZONTAL)),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function thumbnailPathFor(string $path): string
    {
        return preg_replace('/\.[^.\/]+$/', '', $path).'-mini.jpg';
    }
}
