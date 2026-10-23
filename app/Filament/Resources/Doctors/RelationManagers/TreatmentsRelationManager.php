<?php

namespace App\Filament\Resources\Doctors\RelationManagers;

use App\Models\Treatment;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Qué tratamientos hace el doctor y, si es distinto, cuánto demora en cada uno. */
class TreatmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'treatments';

    protected static ?string $title = 'Tratamientos que realiza';

    protected static ?string $modelLabel = 'tratamiento';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::durationInput(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Tratamiento'),
                TextColumn::make('pivot.duration_minutes')
                    ->label('Duración con este doctor')
                    ->state(fn (Treatment $record) => ($record->pivot->duration_minutes ?? $record->duration_minutes).' min')
                    ->description(fn (Treatment $record) => $record->pivot->duration_minutes ? 'Propia (general: '.$record->duration_minutes.' min)' : 'La general'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                AttachAction::make()
                    ->label('Agregar tratamiento')
                    ->preloadRecordSelect()
                    ->multiple()
                    ->schema(fn (AttachAction $action) => [
                        $action->getRecordSelect()->label('Tratamientos'),
                        self::durationInput(),
                    ]),
            ])
            ->recordActions([
                EditAction::make()->label('Duración'),
                DetachAction::make()->label('Quitar'),
            ]);
    }

    private static function durationInput(): TextInput
    {
        return TextInput::make('duration_minutes')
            ->label('Duración propia')
            ->suffix('min')
            ->numeric()
            ->minValue(5)
            ->maxValue(480)
            ->step(5)
            ->helperText('Vacío = la duración general del tratamiento.');
    }
}
