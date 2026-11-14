<?php

namespace App\Filament\Forms;

use App\Services\Web\PublicImages;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Foto para la web: se achica y se guarda sin datos de ubicación en public/media/{carpeta}.
 *
 * El navegador ya la achica y la pasa a JPEG antes de enviarla: una foto de celular o un PNG pesan más
 * de lo que muchos servidores aceptan por archivo (2 MB), y la subida fallaba sin decir por qué.
 */
final class WebPhotoUpload
{
    /**
     * Al crearse el cargador se le pide la salida en JPEG. Filament no ofrece esa opción, y sin ella un PNG
     * sale del navegador igual de pesado. Va entre comillas simples: el atributo se imprime sin escapar.
     */
    private const SEND_AS_JPEG = "\$el.addEventListener('FilePond:init', (event) => event.detail.pond.setOptions({"
        ."imageTransformOutputMimeType: 'image/jpeg', imageTransformOutputQuality: 90, imageTransformCanvasBackgroundColor: '#ffffff'}))";

    public static function make(string $name, string $folder): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk(PublicImages::DISK)
            ->directory($folder)
            ->maxSize(10 * 1024)
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth((string) PublicImages::MAX_SIDE)
            ->automaticallyResizeImagesToHeight((string) PublicImages::MAX_SIDE)
            ->automaticallyUpscaleImagesWhenResizing(false)
            ->extraAlpineAttributes(['x-init' => self::SEND_AS_JPEG])
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => app(PublicImages::class)->store($file, $folder));
    }
}
