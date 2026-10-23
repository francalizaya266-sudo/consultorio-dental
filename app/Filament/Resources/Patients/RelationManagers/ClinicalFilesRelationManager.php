<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\ClinicalFileType;
use App\Filament\Actions\UploadClinicalFilesAction;
use App\Models\ClinicalFile;
use App\Models\Patient;
use App\Services\Clinical\ClinicalFileService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Radiografías, fotos y documentos del paciente, en cuadrícula. Se abren en tamaño completo con un toque. */
class ClinicalFilesRelationManager extends RelationManager
{
    protected static string $relationship = 'clinicalFiles';

    protected static ?string $title = 'Radiografías y fotos';

    protected static ?string $modelLabel = 'archivo';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('viewClinical', $ownerRecord);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->clinicalFiles()->count();

        return $count ? (string) $count : null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Descripción')->maxLength(255),
            Select::make('type')->label('Tipo')->options(ClinicalFileType::class)->required(),
            DatePicker::make('taken_at')->label('Fecha')->maxDate(today())->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Patient $patient */
        $patient = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('patient'))
            ->description(fn () => 'Ocupan '.ClinicalFileService::humanSize(app(ClinicalFileService::class)->usedBytes($patient)).'. Toca una para verla en tamaño completo.')
            ->defaultSort('taken_at', 'desc')
            ->contentGrid(['default' => 2, 'md' => 3, 'xl' => 4])
            ->columns([
                Stack::make([
                    ImageColumn::make('preview')
                        ->state(fn (ClinicalFile $record) => $record->isImage() ? $record->url(thumbnail: true) : null)
                        ->checkFileExistence(false)
                        ->imageHeight(140)
                        ->imageWidth('100%')
                        ->extraImgAttributes(fn (ClinicalFile $record) => ['alt' => $record->title, 'loading' => 'lazy', 'style' => 'object-fit: cover; width: 100%; border-radius: .5rem; background: #0f172a']),
                    TextColumn::make('title')
                        ->weight('bold')
                        ->icon(fn (ClinicalFile $record) => $record->isImage() ? null : Heroicon::OutlinedDocumentText)
                        ->limit(40),
                    TextColumn::make('type')
                        ->badge()
                        ->color('gray'),
                    TextColumn::make('taken_at')
                        ->date('d/m/Y')
                        ->color('gray')
                        ->size('sm'),
                ])->space(2),
            ])
            ->recordUrl(fn (ClinicalFile $record) => $record->url(), shouldOpenInNewTab: true)
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(ClinicalFileType::class),
            ])
            ->headerActions([
                UploadClinicalFilesAction::make(fn () => $patient)
                    ->visible(fn () => auth()->user()->can('updateClinical', $patient)),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()
                    ->iconButton()
                    ->label('Quitar')
                    ->modalHeading('¿Quitar este archivo de la historia?')
                    ->modalDescription('Deja de verse, pero no se pierde: queda guardado y registrado en la auditoría.')
                    ->modalSubmitActionLabel('Quitar'),
            ])
            ->emptyStateHeading('Sin radiografías ni fotos')
            ->emptyStateDescription('Súbelas desde aquí o desde la atención. Se guardan comprimidas.');
    }
}
