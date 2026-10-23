<?php

namespace App\Support;

use GdImage;

/**
 * Fotos subidas desde el celular o la cámara: se enderezan, se achican y se guardan en JPEG.
 * Al volver a codificarlas se pierden los datos EXIF (ubicación, modelo del celular).
 */
final class Images
{
    /** La imagen ya enderezada, o null si GD no puede leerla. */
    public static function read(string $path): ?GdImage
    {
        $contents = @file_get_contents($path);
        $image = $contents !== false ? @imagecreatefromstring($contents) : false;

        return $image instanceof GdImage ? self::orient($image, $path) : null;
    }

    public static function jpeg(GdImage $source, int $maxSide, int $quality = 82): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // Fondo blanco: las radiografías y logos en PNG suelen traer transparencias.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, $quality);

        return (string) ob_get_clean();
    }

    /** Las fotos del celular guardan la orientación aparte (EXIF): se aplica para que no salgan echadas. */
    private static function orient(GdImage $image, string $path): GdImage
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
}
