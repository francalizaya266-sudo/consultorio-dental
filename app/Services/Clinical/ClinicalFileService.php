<?php

namespace App\Services\Clinical;

use App\Enums\ClinicalFileType;
use App\Models\ClinicalFile;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use App\Support\Images;
use Carbon\CarbonInterface;
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
        $source = Images::read($path);

        return $source ? [Images::jpeg($source, self::MAX_SIDE, self::QUALITY), Images::jpeg($source, self::THUMB_SIDE, self::QUALITY)] : null;
    }

    private function thumbnailPathFor(string $path): string
    {
        return preg_replace('/\.[^.\/]+$/', '', $path).'-mini.jpg';
    }
}
