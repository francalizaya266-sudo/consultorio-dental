<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\AppointmentOrigin;
use App\Enums\DocumentType;
use App\Enums\PatientRelationship;
use App\Enums\Sex;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Account;
use App\Models\Patient;
use App\Services\Patients\PatientRegistrar;
use App\Support\Phone;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
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
                            ->maxLength(100)
                            ->live(onBlur: true),
                        TextInput::make('last_name')
                            ->label('Apellidos')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true),
                        self::phoneInput()
                            ->live(onBlur: true)
                            ->visibleOn('create'),
                        // Se corrige después si se registró mal (un niño que quedó como "Titular" del celular de su mamá).
                        self::relationshipSelect()
                            ->visible(fn (string $operation, ?Patient $record) => $operation === 'create' || $record?->account_id !== null),
                        Select::make('document_type')
                            ->label('Documento')
                            ->options(DocumentType::class)
                            ->default(DocumentType::Dni->value)
                            ->live(),
                        self::documentNumberInput()
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('document_type', $get('document_type') instanceof DocumentType ? $get('document_type')->value : $get('document_type')))
                            ->validationMessages(['unique' => 'Ya hay un paciente con este documento.']),
                        self::birthDateInput(),
                        // hiddenOn y no visibleOn: este campo ya decide cuándo se ve según lo que se encontró.
                        self::duplicatesCheck()
                            ->hiddenOn('edit'),
                        Select::make('sex')
                            ->label('Sexo')
                            ->options(Sex::class),
                    ]),
                // Para un menor: quién firma por él los consentimientos y el plan.
                Section::make('Apoderado')
                    ->description('Es menor de edad: su apoderado firma los consentimientos y el plan de tratamiento.')
                    ->visible(fn (Get $get) => filled($get('birth_date')) && Carbon::parse($get('birth_date'))->age < 18)
                    ->schema([
                        TextInput::make('guardian_name')
                            ->label('Nombre completo')
                            ->maxLength(150)
                            ->placeholder(fn (?Patient $record) => self::holderOf($record)?->full_name),
                        TextInput::make('guardian_document')
                            ->label('DNI u otro documento')
                            ->maxLength(20)
                            ->dehydrateStateUsing(fn (?string $state) => DocumentType::clean($state)),
                        Select::make('guardian_relationship')
                            ->label('Es su')
                            ->options([
                                PatientRelationship::Parent->value => 'Padre / madre',
                                PatientRelationship::Grandparent->value => 'Abuelo(a)',
                                PatientRelationship::Sibling->value => 'Hermano(a)',
                                PatientRelationship::Other->value => 'Otro (tutor)',
                            ])
                            ->default(PatientRelationship::Parent->value),
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

    /** El titular del celular del paciente, si no es él mismo: suele ser quien lo trae. */
    private static function holderOf(?Patient $patient): ?Patient
    {
        return $patient?->account_id && $patient->relationship !== PatientRelationship::Self
            ? Patient::query()->where('account_id', $patient->account_id)->where('relationship', PatientRelationship::Self)->first()
            : null;
    }

    /**
     * Parentesco con el titular del celular. Un celular tiene un solo titular: si ya lo tiene, se pide el
     * parentesco con esa persona (así un niño no queda como "Titular" del celular de su mamá).
     */
    public static function relationshipSelect(): Select
    {
        return Select::make('relationship')
            ->label('Parentesco con el titular del celular')
            ->options(PatientRelationship::class)
            ->helperText('"Titular" si el celular es suyo.')
            ->rule(fn (Get $get, ?Model $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
                $relationship = $value instanceof PatientRelationship ? $value : PatientRelationship::tryFrom((string) $value);
                $phone = $get('phone');
                $accountId = filled($phone) && Phone::isValid($phone)
                    ? Account::query()->where('phone', Phone::normalize($phone))->value('id')
                    : ($record instanceof Patient ? $record->account_id : null);

                if ($relationship !== PatientRelationship::Self || $accountId === null) {
                    return;
                }

                $holder = Patient::query()
                    ->where('account_id', $accountId)
                    ->where('relationship', PatientRelationship::Self)
                    ->when($record instanceof Patient, fn ($query) => $query->whereKeyNot($record->getKey()))
                    ->first();

                if ($holder) {
                    $fail('Este celular ya es de '.$holder->full_name.': elige el parentesco con esa persona.');
                }
            });
    }

    /**
     * Fecha de nacimiento, con la fecha en palabras y la edad debajo. El navegador la muestra como día/mes o
     * mes/día según su idioma: así se ve al instante si se escribió al revés.
     */
    public static function birthDateInput(): DatePicker
    {
        return DatePicker::make('birth_date')
            ->label('Fecha de nacimiento')
            ->maxDate(today())
            ->live()
            ->helperText(fn (mixed $state) => self::birthDateInWords($state));
    }

    /** "12 de abril de 1985 · 41 años"; nada si aún no hay fecha o no es una fecha que ya pasó. */
    public static function birthDateInWords(mixed $state): ?string
    {
        try {
            $date = filled($state) ? Carbon::parse($state) : null;
        } catch (InvalidFormatException) {
            $date = null;
        }

        if ($date === null || $date->isFuture()) {
            return null;
        }

        $age = $date->age;

        return $date->translatedFormat('j \d\e F \d\e Y').' · '.match (true) {
            $age >= 2 => $age.' años',
            $age === 1 => '1 año',
            default => 'menos de un año',
        };
    }

    /** Número de documento: se guarda sin espacios ni guiones y se revisa según el tipo (el DNI tiene 8 dígitos). */
    public static function documentNumberInput(string $typeField = 'document_type'): TextInput
    {
        return TextInput::make('document_number')
            ->label('Número de documento')
            ->maxLength(20)
            ->live(onBlur: true)
            ->mutateStateForValidationUsing(fn (?string $state) => DocumentType::clean($state))
            ->dehydrateStateUsing(fn (?string $state) => DocumentType::clean($state))
            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get, $typeField) {
                $type = $get($typeField);
                $type = $type instanceof DocumentType ? $type : (DocumentType::tryFrom((string) $type) ?? DocumentType::Dni);

                if (filled($value) && $type->pattern() !== null && ! preg_match($type->pattern(), (string) $value)) {
                    $fail($type->invalidMessage());
                }
            });
    }

    /**
     * Mientras se escribe el nombre, el documento, la fecha de nacimiento o el celular, los pacientes que ya
     * están y podrían ser el mismo. Un paciente repetido parte su historia en dos: hay que elegir si es uno de
     * ellos o es otra persona. Al elegir uno, $onPick decide qué hacer (abrir su ficha, usarlo en la cita).
     *
     * @param  string  $path  Dónde están los datos del paciente respecto de este campo ('' o '../patient.' por ejemplo).
     * @param  Closure(int, Set, mixed): void|null  $onPick
     */
    public static function duplicatesCheck(string $path = '', ?Closure $onPick = null): Radio
    {
        // Se busca una vez por cada cambio de los datos, no una vez por cada parte del campo.
        $found = [];
        $candidates = function (Get $get) use ($path, &$found) {
            $data = collect(['first_name', 'last_name', 'birth_date', 'document_number', 'phone'])
                ->mapWithKeys(fn (string $field) => [$field => $get($path.$field)])
                ->all();

            return $found[md5(serialize($data))] ??= app(PatientRegistrar::class)->possibleDuplicates($data);
        };

        return Radio::make('same_as')
            ->label(function (Get $get) use ($candidates) {
                $found = $candidates($get);

                return match (true) {
                    $found->contains('reason', 'mismo documento') => 'Ya hay un paciente con este documento: ¿es él?',
                    $found->every(fn (array $row) => $row['reason'] === 'mismo celular') => 'Este celular ya tiene a '.Arr::join($found->map(fn (array $row) => $row['patient']->first_name)->all(), ', ', ' y ').': ¿es uno de ellos?',
                    default => '¿Ya está registrado? Encontramos pacientes parecidos',
                };
            })
            ->helperText('Un paciente repetido parte su historia en dos.')
            ->options(fn (Get $get) => $candidates($get)
                ->mapWithKeys(fn (array $row) => [$row['patient']->getKey() => 'Es '.$row['patient']->full_name])
                ->put('new', 'No, es otra persona')
                ->all())
            ->descriptions(fn (Get $get) => $candidates($get)
                ->mapWithKeys(fn (array $row) => [$row['patient']->getKey() => collect([
                    $row['patient']->document_number ? $row['patient']->document_type?->getLabel().' '.$row['patient']->document_number : null,
                    $row['patient']->age !== null ? $row['patient']->age.' años' : null,
                    $row['patient']->account ? Phone::display($row['patient']->account->phone) : null,
                    $row['patient']->relationship && $row['reason'] === 'mismo celular' ? $row['patient']->relationship->getLabel() : null,
                    ucfirst($row['reason']),
                ])->filter()->implode(' · ')])
                ->all())
            ->visible(fn (Get $get) => $candidates($get)->isNotEmpty())
            ->required()
            ->validationMessages(['required' => 'Elige si es uno de ellos o es otra persona.'])
            ->live()
            ->afterStateUpdated(function (mixed $state, Set $set, $livewire) use ($onPick) {
                if (filled($state) && $state !== 'new') {
                    $onPick
                        ? $onPick((int) $state, $set, $livewire)
                        : $livewire->redirect(PatientResource::getUrl('view', ['record' => $state]));
                }
            })
            ->dehydrated(false)
            ->columnSpanFull();
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
                if (filled($value) && ($problem = Phone::problem((string) $value)) !== null) {
                    $fail($problem);
                }
            });
    }
}
