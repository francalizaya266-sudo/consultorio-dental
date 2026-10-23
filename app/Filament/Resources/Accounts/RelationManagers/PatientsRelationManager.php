<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\PatientRelationship;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Patient;
use App\Services\Patients\PatientRegistrar;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** La familia de un celular: titular y familiares a cargo. */
class PatientsRelationManager extends RelationManager
{
    protected static string $relationship = 'patients';

    protected static ?string $title = 'Familia';

    protected static ?string $modelLabel = 'familiar';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    TextInput::make('first_name')->label('Nombres')->required(),
                    TextInput::make('last_name')->label('Apellidos')->required(),
                    Select::make('relationship')
                        ->label('Parentesco con el titular')
                        ->options(PatientRelationship::class)
                        ->default(PatientRelationship::Child->value)
                        ->required(),
                    DatePicker::make('birth_date')->label('Fecha de nacimiento')->maxDate(today()),
                    TextInput::make('document_number')->label('DNI')->maxLength(20),
                ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('account'))
            ->columns([
                TextColumn::make('first_name')
                    ->label('Paciente')
                    ->formatStateUsing(fn (Patient $record) => $record->full_name),
                TextColumn::make('relationship')
                    ->label('Parentesco'),
                TextColumn::make('birth_date')
                    ->label('Edad')
                    ->formatStateUsing(fn (Patient $record) => $record->age !== null ? $record->age.' años' : null)
                    ->placeholder('—'),
                TextColumn::make('last_visit_at')
                    ->label('Última visita')
                    ->date('d/m/Y')
                    ->placeholder('Aún no viene'),
            ])
            ->recordUrl(fn (Patient $record) => PatientResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar familiar')
                    ->using(fn (array $data) => app(PatientRegistrar::class)->register(
                        array_filter($data, fn ($value) => $value !== null && $value !== '') + ['phone' => $this->getOwnerRecord()->phone],
                    )),
            ]);
    }
}
