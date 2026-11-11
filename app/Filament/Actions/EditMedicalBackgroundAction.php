<?php

namespace App\Filament\Actions;

use App\Enums\Sex;
use App\Models\MedicalBackground;
use App\Models\Patient;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Registrar o corregir los antecedentes médicos (alergias, enfermedades, medicamentos, embarazo) en una
 * ventana, sin salir de donde se está: la historia clínica o la atención en curso.
 */
final class EditMedicalBackgroundAction
{
    /** @param  Closure  $patient  Devuelve el paciente (recibe el registro de la pantalla como $record). */
    public static function make(Closure $patient, string $name = 'editBackground'): Action
    {
        $resolve = fn (Action $action): Patient => $action->evaluate($patient);

        return Action::make($name)
            ->label(fn (Action $action) => $resolve($action)->medicalBackground ? 'Editar' : 'Registrar antecedentes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize(fn (Action $action) => auth()->user()->can('updateClinical', $resolve($action)))
            ->modalHeading('Antecedentes médicos')
            ->modalDescription('Pregunta por alergias, enfermedades y medicamentos. Si no tiene, toca "No tiene" para dejarlo registrado.')
            ->modalSubmitActionLabel('Guardar')
            ->fillForm(fn (Action $action) => $resolve($action)->medicalBackground?->only([
                'conditions', 'allergies', 'medications', 'surgeries', 'is_pregnant', 'pregnancy_weeks', 'is_breastfeeding', 'smoker', 'notes',
            ]) ?? [])
            ->schema(fn (Action $action) => [
                Textarea::make('allergies')
                    ->label('Alergias')
                    ->placeholder('Ej.: penicilina, látex.')
                    ->hintAction(self::noneAction('noAllergies', 'allergies'))
                    ->rows(2),
                CheckboxList::make('conditions')
                    ->label('Enfermedades')
                    ->options(MedicalBackground::CONDITIONS)
                    ->columns(2),
                Textarea::make('medications')
                    ->label('Medicamentos que toma')
                    ->placeholder('Ej.: anticoagulantes, insulina')
                    ->hintAction(self::noneAction('noMedications', 'medications'))
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
                            ->visible($resolve($action)->sex !== Sex::Male),
                        TextInput::make('pregnancy_weeks')
                            ->label('Semanas')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(42)
                            ->visible(fn (Get $get) => (bool) $get('is_pregnant')),
                        Toggle::make('is_breastfeeding')
                            ->label('Dando de lactar')
                            ->visible($resolve($action)->sex !== Sex::Male),
                    ]),
                Textarea::make('notes')
                    ->label('Otras notas')
                    ->rows(2),
            ])
            ->action(function (array $data, Action $action) use ($resolve) {
                $patient = $resolve($action);

                $patient->medicalBackground()->updateOrCreate([], [
                    ...$data,
                    'pregnancy_weeks' => ($data['is_pregnant'] ?? false) ? ($data['pregnancy_weeks'] ?? null) : null,
                    'updated_by_user_id' => auth()->id(),
                    // Guardados por el equipo, ya están confirmados aunque los haya llenado el paciente.
                    'filled_by_patient_at' => null,
                ]);
                $patient->unsetRelation('medicalBackground');

                Notification::make()->success()->title('Antecedentes guardados')->send();
            });
    }

    /** "No tiene", de un toque: deja escrito "Ninguna" para que se sepa que se preguntó. */
    private static function noneAction(string $name, string $field): Action
    {
        return Action::make($name)
            ->label('No tiene')
            ->icon(Heroicon::OutlinedCheck)
            ->action(fn (Set $set) => $set($field, 'Ninguna'));
    }
}
