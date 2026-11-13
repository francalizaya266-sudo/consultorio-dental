<?php

namespace App\Filament\Resources\Doctors\RelationManagers;

use App\Models\Treatment;
use App\Services\Agenda\LearnedDurations;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Qué tratamientos hace el doctor y cuánto se le reserva en cada uno: lo puesto a mano, lo que suele tardar o la duración general. */
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
            ->stackedOnMobile()
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Tratamiento'),
                TextColumn::make('pivot.duration_minutes')
                    ->label('Se reserva')
                    ->state(fn (Treatment $record) => $this->getOwnerRecord()->durationFor($record).' min')
                    ->description(fn (Treatment $record) => self::durationSource($record))
                    ->wrap(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                // Con las palabras del consultorio: decía "Vincular tratamiento" y "Vincular y vincular otro".
                AttachAction::make()
                    ->label('Agregar tratamiento')
                    ->modalHeading('Agregar tratamientos que realiza')
                    ->modalSubmitActionLabel('Agregar')
                    ->attachAnother(false)
                    ->successNotificationTitle('Tratamientos agregados')
                    ->preloadRecordSelect()
                    ->multiple()
                    ->schema(fn (AttachAction $action) => [
                        $action->getRecordSelect()->label('Tratamientos'),
                        self::durationInput(),
                    ]),
            ])
            ->recordActions([
                EditAction::make()->label('Duración'),
                DetachAction::make()
                    ->label('Quitar')
                    ->modalHeading(fn (Treatment $record) => '¿Quitar '.$record->name.'?')
                    ->modalDescription('Ya no se le podrá agendar este tratamiento. Las citas ya dadas siguen igual.')
                    ->modalSubmitActionLabel('Quitar')
                    ->successNotificationTitle('Tratamiento quitado'),
            ])
            ->emptyStateHeading('Todavía no realiza ningún tratamiento')
            ->emptyStateDescription('Sin tratamientos no se le puede agendar. Agrega los que realiza.');
    }

    /** De dónde sale lo que se reserva: la propia, lo que suele tardar (con cuántas atenciones) o la general. */
    private static function durationSource(Treatment $record): string
    {
        $learned = $record->pivot->learned_minutes !== null ? (int) $record->pivot->learned_minutes : null;
        $measured = $learned !== null ? "En la práctica suele tardar {$learned} min ({$record->pivot->learned_samples} atenciones)" : null;

        return match (true) {
            (bool) $record->pivot->duration_minutes => collect(['Puesta a mano (general: '.$record->duration_minutes.' min)', $measured])->filter()->implode('. '),
            LearnedDurations::applies($learned, (int) $record->duration_minutes) => "Lo que suele tardar: {$record->pivot->learned_samples} atenciones medidas (general: {$record->duration_minutes} min)",
            default => collect(['La general', $measured])->filter()->implode('. '),
        };
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
            ->helperText('Vacío = lo que suele tardar en la práctica (si ya hay atenciones medidas) o la duración general.');
    }
}
