<?php

namespace App\Support;

use App\Exceptions\ClinicalException;
use App\Services\Clinical\ClinicalFileService;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Firmas hechas con el dedo en la pantalla (consentimientos y planes de tratamiento).
 * Llegan del navegador como PNG en base64; se vuelven a generar para guardar solo la imagen.
 */
final class Signatures
{
    /** Guarda la firma en el disco privado de la historia del paciente y devuelve su ruta. */
    public static function store(string $dataUrl, int $patientId): string
    {
        $path = 'clinica/'.$patientId.'/firmas/'.Str::uuid().'.png';
        Storage::disk(ClinicalFileService::DISK)->put($path, self::png($dataUrl));

        return $path;
    }

    /** Firma lista para mostrarla en una hoja impresa. */
    public static function dataUri(?string $path): ?string
    {
        $disk = Storage::disk(ClinicalFileService::DISK);

        return $path && $disk->exists($path)
            ? 'data:image/png;base64,'.base64_encode((string) $disk->get($path))
            : null;
    }

    public static function png(string $dataUrl): string
    {
        $data = str_starts_with($dataUrl, 'data:image/png;base64,') ? base64_decode(substr($dataUrl, 22), true) : false;
        $image = $data ? @imagecreatefromstring($data) : false;

        if (! $image instanceof GdImage) {
            throw ClinicalException::invalidSignature();
        }

        $image = self::trim($image);
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /** Se recorta el espacio vacío alrededor del trazo para que en la hoja impresa se vea del tamaño justo. */
    private static function trim(GdImage $image, int $padding = 8): GdImage
    {
        $cropped = imagecropauto($image, IMG_CROP_SIDES);

        if (! $cropped instanceof GdImage) {
            imagesavealpha($image, true);

            return $image;
        }

        $width = imagesx($cropped) + 2 * $padding;
        $height = imagesy($cropped) + 2 * $padding;
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagesavealpha($canvas, true);
        imagecopy($canvas, $cropped, $padding, $padding, 0, 0, imagesx($cropped), imagesy($cropped));

        return $canvas;
    }
}
