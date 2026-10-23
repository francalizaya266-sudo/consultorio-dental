<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Enums\AppointmentOrigin;
use App\Enums\PatientRelationship;
use App\Filament\Support\SlotPicker;
use App\Models\Patient;
use App\Services\Patients\PatientRegistrar;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Nueva cita desde el panel (recepción o doctor). */
class AppointmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Paciente')
                    ->schema([
                        self::patientSelect(),
                    ]),
                Section::make('Horario')
                    ->columns(2)
                    ->schema(SlotPicker::fields()),
                Section::make('Detalles')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Select::make('origin')
                            ->label('¿Cómo nos encontró?')
                            ->options(AppointmentOrigin::class),
                        Toggle::make('fixed_date')
                            ->label('Fecha fija (el paciente no la mueve solo)')
                            ->inline(false),
                        Textarea::make('internal_notes')
                            ->label('Notas internas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function patientSelect(): Select
    {
        return Select::make('patient_id')
            ->label('Paciente')
            ->placeholder('Busca por nombre, DNI o celular')
            ->searchable()
            ->required()
            ->default(fn () => request()->integer('patient') ?: null)
            ->getSearchResultsUsing(fn (string $search) => Patient::query()
                ->search($search)
                ->with('account')
                ->limit(20)
                ->get()
                ->mapWithKeys(fn (Patient $p) => [$p->id => $p->selectLabel()]))
            ->getOptionLabelUsing(fn ($value) => Patient::with('account')->find($value)?->selectLabel())
            ->createOptionModalHeading('Paciente nuevo')
            ->createOptionAction(fn (Action $action) => $action->modalSubmitActionLabel('Guardar paciente')->tooltip('Paciente nuevo'))
            ->createOptionForm([
                Grid::make(2)->schema([
                    TextInput::make('first_name')->label('Nombres')->required(),
                    TextInput::make('last_name')->label('Apellidos')->required(),
                    TextInput::make('phone')
                        ->label('Celular')
                        ->tel()
                        ->helperText('Si es un familiar a cargo, usa el celular del titular.')
                        ->rule(fn () => function (string $attribute, $value, $fail) {
                            if (filled($value) && ! Phone::isValid($value)) {
                                $fail('Escribe un celular válido de 9 dígitos.');
                            }
                        }),
                    Select::make('relationship')
                        ->label('Parentesco con el titular del celular')
                        ->options(PatientRelationship::class)
                        ->default(PatientRelationship::Self->value),
                    DatePicker::make('birth_date')->label('Fecha de nacimiento')->maxDate(today()),
                    TextInput::make('document_number')->label('DNI')->maxLength(20),
                ]),
            ])
            ->createOptionUsing(fn (array $data) => app(PatientRegistrar::class)->register(array_filter($data, fn ($v) => $v !== null && $v !== ''))->getKey());
    }
}
