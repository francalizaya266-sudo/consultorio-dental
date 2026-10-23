<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\AppointmentOrigin;
use App\Enums\DocumentType;
use App\Enums\PatientRelationship;
use App\Enums\Sex;
use App\Support\Phone;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos del paciente')
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')
                            ->label('Nombres')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('last_name')
                            ->label('Apellidos')
                            ->required()
                            ->maxLength(100),
                        self::phoneInput()
                            ->visibleOn('create'),
                        Select::make('relationship')
                            ->label('Parentesco con el titular del celular')
                            ->options(PatientRelationship::class)
                            ->helperText('"Titular" si el celular es suyo.')
                            ->visibleOn('create'),
                        Select::make('document_type')
                            ->label('Documento')
                            ->options(DocumentType::class)
                            ->default(DocumentType::Dni->value)
                            ->live(),
                        TextInput::make('document_number')
                            ->label('Número de documento')
                            ->maxLength(20)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('document_type', $get('document_type')))
                            ->validationMessages(['unique' => 'Ya hay un paciente con este documento.']),
                        DatePicker::make('birth_date')
                            ->label('Fecha de nacimiento')
                            ->maxDate(today()),
                        Select::make('sex')
                            ->label('Sexo')
                            ->options(Sex::class),
                    ]),
                Section::make('Contacto y otros datos')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('email')
                            ->label('Correo')
                            ->email(),
                        TextInput::make('occupation')
                            ->label('Ocupación'),
                        TextInput::make('address')
                            ->label('Dirección'),
                        TextInput::make('district')
                            ->label('Distrito'),
                        TextInput::make('emergency_contact_name')
                            ->label('Contacto de emergencia'),
                        TextInput::make('emergency_contact_phone')
                            ->label('Celular de emergencia')
                            ->tel(),
                        Select::make('origin')
                            ->label('¿Cómo nos encontró?')
                            ->options(AppointmentOrigin::class),
                        Textarea::make('admin_notes')
                            ->label('Notas internas (no clínicas)')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /** Celular peruano; el de un menor o familiar a cargo es el del titular. */
    public static function phoneInput(string $name = 'phone'): TextInput
    {
        return TextInput::make($name)
            ->label('Celular')
            ->tel()
            ->placeholder('987 654 321')
            ->helperText('Si es un familiar a cargo, usa el celular del titular: quedan agrupados como familia.')
            ->rule(fn () => function (string $attribute, $value, $fail) {
                if (filled($value) && ! Phone::isValid($value)) {
                    $fail('Escribe un celular válido de 9 dígitos.');
                }
            });
    }
}
