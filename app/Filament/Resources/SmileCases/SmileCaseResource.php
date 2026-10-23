<?php

namespace App\Filament\Resources\SmileCases;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Forms\WebPhotoUpload;
use App\Filament\Resources\SmileCases\Pages\ManageSmileCases;
use App\Models\Patient;
use App\Models\SmileCase;
use App\Services\Web\PublicImages;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Antes y después para la web. Solo se publica con la autorización escrita del paciente. */
class SmileCaseResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'antes-y-despues';

    protected static ?string $model = SmileCase::class;

    protected static ?string $modelLabel = 'caso de antes y después';

    protected static ?string $pluralModelLabel = 'antes y después';

    protected static string|UnitEnum|null $navigationGroup = 'Web';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')
                    ->label('Título')
                    ->placeholder('Por ejemplo: Blanqueamiento en 2 sesiones')
                    ->required()
                    ->maxLength(120)
                    ->columnSpanFull(),
                WebPhotoUpload::make('before_path', 'casos')
                    ->label('Foto de antes')
                    ->required(),
                WebPhotoUpload::make('after_path', 'casos')
                    ->label('Foto de después')
                    ->required(),
                Textarea::make('description')
                    ->label('Qué se hizo (opcional)')
                    ->rows(3)
                    ->maxLength(600)
                    ->columnSpanFull(),
                Select::make('treatment_id')
                    ->label('Tratamiento')
                    ->relationship('treatment', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('doctor_id')
                    ->label('Doctor')
                    ->relationship('doctor', 'name')
                    ->preload(),
                Select::make('patient_id')
                    ->label('Paciente (no sale en la web)')
                    ->relationship('patient', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn (Patient $record) => $record->full_name)
                    ->searchable(['first_name', 'last_name', 'document_number'])
                    ->helperText('Para saber de quién son las fotos si pide retirarlas.'),
                Toggle::make('is_published')
                    ->label('Publicado')
                    ->default(true),
                Checkbox::make('consent')
                    ->label('El paciente autorizó por escrito publicar estas fotos')
                    ->helperText('Sin esa autorización no se pueden publicar (Ley 29733). Si la retira, desactiva "Publicado".')
                    ->accepted()
                    ->dehydrated(false)
                    ->visibleOn('create')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('before_path')
                    ->label('Antes')
                    ->disk(PublicImages::DISK)
                    ->square(),
                ImageColumn::make('after_path')
                    ->label('Después')
                    ->disk(PublicImages::DISK)
                    ->square(),
                TextColumn::make('title')
                    ->label('Caso')
                    ->searchable()
                    ->description(fn (SmileCase $record) => $record->treatment?->name),
                TextColumn::make('consent_confirmed_at')
                    ->label('Autorización')
                    ->date('d/m/Y')
                    ->description(fn (SmileCase $record) => $record->consentConfirmedBy?->name),
                ToggleColumn::make('is_published')
                    ->label('Publicado'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->emptyStateHeading('Todavía no hay casos')
            ->emptyStateDescription('Sube las fotos de antes y después de pacientes que autorizaron publicarlas.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSmileCases::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['treatment', 'consentConfirmedBy'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
