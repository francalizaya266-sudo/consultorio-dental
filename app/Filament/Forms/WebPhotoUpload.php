<?php

namespace App\Filament\Forms;

use App\Services\Web\PublicImages;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** Foto para la web: se achica y se guarda sin datos de ubicación en public/media/{carpeta}. */
final class WebPhotoUpload
{
    public static function make(string $name, string $folder): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk(PublicImages::DISK)
            ->directory($folder)
            ->maxSize(10 * 1024)
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => app(PublicImages::class)->store($file, $folder));
    }
}
