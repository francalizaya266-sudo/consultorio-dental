<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\Sex;
use App\Filament\Support\ClinicalAlerts;
use App\Livewire\OdontogramChart;
use App\Models\MedicalBackground;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ClinicalRecordInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Group::make(fn (Patient $record) => array_filter([ClinicalAlerts::callout($record, withLink: false)])),

                Section::make('Antecedentes médicos')
                    ->key('background')
                    ->icon(Heroicon::OutlinedHeart)
                    ->description(fn (Patient $record) => $record->medicalBackground
                        ? 'Actualizado el '.$record->medicalBackground->updated_at->format('d/m/Y').($record->medicalBackground->updatedBy ? ' por '.$record->medicalBackground->updatedBy->name : '')
                        : 'Todavía no se registraron.')
                    ->headerActions([self::editBackgroundAction()])
                    ->columns(3)
                    ->collapsible()
                    ->collapsed(fn (Patient $record) => $record->medicalBackground !== null)
                    ->schema([
                        TextEntry::make('medicalBackground.conditions')
                            ->label('Enfermedades')
                            ->state(fn (Patient $record) => collect($record->medicalBackground?->conditions ?? [])->map(fn (string $key) => MedicalBackground::CONDITIONS[$key] ?? $key)->all())
                            ->badge()
                            ->color('danger')
                            ->placeholder('Ninguna registrada')
                            ->columnSpanFull(),
                        TextEntry::make('medicalBackground.allergies')->label('Alergias')->placeholder('—'),
                        TextEntry::make('medicalBackground.medications')->label('Medicamentos que toma')->placeholder('—'),
                        TextEntry::make('medicalBackground.surgeries')->label('Operaciones')->placeholder('—'),
                        TextEntry::make('medicalBackground.smoker')
                            ->label('Fuma')
                            ->formatStateUsing(fn (?bool $state) => $state ? 'Sí' : 'No')
                            ->placeholder('No registrado'),
                        TextEntry::make('pregnancy')
                            ->label('Embarazo / lactancia')
                            ->state(fn (Patient $record) => collect([
                                $record->medicalBackground?->is_pregnant ? 'Embarazada'.($record->medicalBackground->pregnancy_weeks ? " ({$record->medicalBackground->pregnancy_weeks} sem.)" : '') : null,
                                $record->medicalBackground?->is_breastfeeding ? 'Dando de lactar' : null,
                            ])->filter()->implode(' · ') ?: null)
                            ->placeholder('No')
                            ->visible(fn (Patient $record) => $record->sex !== Sex::Male),
                        TextEntry::make('medicalBackground.notes')->label('Otras notas')->placeholder('—'),
                    ]),

                Section::make('Odontograma')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->description('El más reciente. Elige una fecha para ver cómo estaba antes.')
                    ->collapsible()
                    ->schema([
                        Livewire::make(OdontogramChart::class)->key('odontogram'),
                    ]),
            ]);
    }

    private static function editBackgroundAction(): Action
    {
        return Action::make('editBackground')
            ->label(fn (Patient $record) => $record->medicalBackground ? 'Editar' : 'Registrar antecedentes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn (Patient $record) => auth()->user()->can('updateClinical', $record))
            ->modalHeading('Antecedentes médicos')
            ->modalSubmitActionLabel('Guardar')
            ->fillForm(fn (Patient $record) => $record->medicalBackground?->only([
                'conditions', 'allergies', 'medications', 'surgeries', 'is_pregnant', 'pregnancy_weeks', 'is_breastfeeding', 'smoker', 'notes',
            ]) ?? [])
            ->schema(fn (Patient $record) => [
                CheckboxList::make('conditions')
                    ->label('Enfermedades')
                    ->options(MedicalBackground::CONDITIONS)
                    ->columns(2),
                Textarea::make('allergies')
                    ->label('Alergias')
                    ->placeholder('Ej.: penicilina, látex. Escribe "Ninguna" si no tiene.')
                    ->rows(2),
                Textarea::make('medications')
                    ->label('Medicamentos que toma')
                    ->placeholder('Ej.: anticoagulantes, insulina')
                    ->rows(2),
                Textarea::make('surgeries')
                    ->label('Operaciones o internamientos')
                    ->rows(2),
                Grid::make(3)
                    ->schema([
                        Toggle::make('smoker')->label('Fuma'),
                        Toggle::make('is_pregnant')
                            ->label('Embarazada')
                            ->live()
                            ->visible($record->sex !== Sex::Male),
                        TextInput::make('pregnancy_weeks')
                            ->label('Semanas')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(42)
                            ->visible(fn (Get $get) => (bool) $get('is_pregnant')),
                        Toggle::make('is_breastfeeding')
                            ->label('Dando de lactar')
                            ->visible($record->sex !== Sex::Male),
                    ]),
                Textarea::make('notes')
                    ->label('Otras notas')
                    ->rows(2),
            ])
            ->action(function (array $data, Patient $record) {
                $record->medicalBackground()->updateOrCreate([], [
                    ...$data,
                    'pregnancy_weeks' => ($data['is_pregnant'] ?? false) ? ($data['pregnancy_weeks'] ?? null) : null,
                    'updated_by_user_id' => auth()->id(),
                ]);
                $record->unsetRelation('medicalBackground');

                Notification::make()->success()->title('Antecedentes guardados')->send();
            });
    }
}
