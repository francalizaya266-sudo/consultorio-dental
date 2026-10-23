<?php

namespace App\Filament\Actions;

use App\Enums\ClinicalFileType;
use App\Services\Clinical\ClinicalFileService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Subir radiografías, fotos o documentos (varios a la vez, también desde la cámara del celular).
 * El navegador ya los achica antes de subir y el servidor los vuelve a comprimir.
 */
final class UploadClinicalFilesAction
{
    /**
     * @param  Closure  $patient  Devuelve el paciente (recibe $record, $livewire, etc.).
     * @param  Closure|null  $consultation  Devuelve la atención a la que quedan unidos.
     */
    public static function make(Closure $patient, ?Closure $consultation = null, string $name = 'uploadFiles'): Action
    {
        return Action::make($name)
            ->label('Subir radiografías o fotos')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Subir radiografías o fotos')
            ->modalDescription('Puedes elegir varias a la vez o tomarlas con la cámara. Se guardan comprimidas y solo las ve el equipo clínico.')
            ->modalSubmitActionLabel('Guardar')
            ->schema([
                FileUpload::make('files')
                    ->hiddenLabel()
                    ->multiple()
                    ->required()
                    ->storeFiles(false)
                    ->acceptedFileTypes(ClinicalFileService::ACCEPTED)
                    ->maxSize(10 * 1024)
                    ->maxFiles(20)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth((string) ClinicalFileService::MAX_SIDE)
                    ->imageResizeTargetHeight((string) ClinicalFileService::MAX_SIDE)
                    ->imageResizeUpscale(false)
                    ->panelLayout('grid'),
                ToggleButtons::make('type')
                    ->label('Tipo')
                    ->options([
                        ClinicalFileType::Xray->value => ClinicalFileType::Xray->getLabel(),
                        ClinicalFileType::Photo->value => ClinicalFileType::Photo->getLabel(),
                        ClinicalFileType::Document->value => ClinicalFileType::Document->getLabel(),
                    ])
                    ->default(ClinicalFileType::Xray->value)
                    ->inline()
                    ->grouped()
                    ->required(),
                Grid::make(2)->schema([
                    TextInput::make('title')
                        ->label('Descripción (opcional)')
                        ->placeholder('Ej.: periapical de 36')
                        ->maxLength(255),
                    DatePicker::make('taken_at')
                        ->label('Fecha')
                        ->default(today())
                        ->maxDate(today())
                        ->required(),
                ]),
            ])
            ->action(function (array $data, Action $action) use ($patient, $consultation) {
                $files = app(ClinicalFileService::class);
                $uploads = array_values(array_filter((array) $data['files'], fn ($file) => $file instanceof TemporaryUploadedFile));

                foreach ($uploads as $i => $upload) {
                    $title = filled($data['title']) && count($uploads) > 1 ? $data['title'].' ('.($i + 1).')' : $data['title'];

                    $files->store($action->evaluate($patient), $upload, ClinicalFileType::from($data['type']), auth()->user(), $consultation ? $action->evaluate($consultation) : null, $title, Carbon::parse($data['taken_at']));
                }

                Notification::make()->success()->title(count($uploads) === 1 ? 'Archivo guardado' : count($uploads).' archivos guardados')->send();
            });
    }
}
