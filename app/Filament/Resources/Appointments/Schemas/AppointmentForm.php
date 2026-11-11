<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Enums\AppointmentOrigin;
use App\Enums\DocumentType;
use App\Enums\PatientRelationship;
use App\Filament\Resources\Patients\Schemas\PatientForm;
use App\Filament\Support\SlotPicker;
use App\Models\Patient;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Phone;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                        Toggle::make('new_patient')
                            ->label('Es un paciente nuevo (aún no está registrado)')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('patient_id', null)),
                        self::patientSelect()
                            ->hidden(fn (Get $get) => (bool) $get('new_patient'))
                            ->required(fn (Get $get) => ! $get('new_patient'))
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                $set('treatment_plan_item_id', null);
                                // Si suele venir un mismo día, se propone el próximo, con sus horarios de siempre primero.
                                SlotPicker::followUsualDay($get, $set);
                            }),
                        Grid::make(2)
                            ->statePath('patient')
                            ->visible(fn (Get $get) => (bool) $get('new_patient'))
                            ->schema(self::newPatientFields()),
                        self::planItemSelect(),
                        self::planAcceptedCheckbox(),
                        // Junto al paciente, donde recepción lo pregunta. Solo a quien viene por primera vez: a los
                        // demás ya se les preguntó (los reportes usan el origen del paciente).
                        Select::make('origin')
                            ->label('¿Cómo nos encontró?')
                            ->options(AppointmentOrigin::class)
                            ->visible(fn (Get $get) => self::isFirstTime($get)),
                    ]),
                Section::make('Horario')
                    ->columns(2)
                    ->schema(SlotPicker::fields()),
                Section::make('Detalles')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
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

    /** Paciente nuevo, o uno registrado que nunca vino y del que no se sabe cómo llegó. */
    private static function isFirstTime(Get $get): bool
    {
        if ($get('new_patient')) {
            return true;
        }

        return filled($get('patient_id'))
            && Patient::query()->whereKey($get('patient_id'))->whereNull('first_visit_at')->whereNull('origin')->exists();
    }

    /** Si el paciente tiene un plan de tratamiento, la cita puede ser una de sus sesiones: al atenderla se marca sola. */
    public static function planItemSelect(): Select
    {
        return Select::make('treatment_plan_item_id')
            ->label('¿Es una sesión de su plan de tratamiento?')
            ->placeholder('No, es otra cita')
            ->options(fn (Get $get) => self::planItemOptions($get('patient_id')))
            ->visible(fn (Get $get) => self::planItemOptions($get('patient_id')) !== [])
            ->default(fn () => request()->integer('plan_item') ?: null)
            ->in(fn (Get $get) => array_keys(self::planItemOptions($get('patient_id'))))
            ->live()
            ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                $item = TreatmentPlanItem::with('plan')->find($state);

                // Se completa con el doctor del plan y el tratamiento del procedimiento.
                if ($item && blank($get('doctor_id'))) {
                    $set('doctor_id', $item->plan->doctor_id);
                }

                if ($item?->treatment_id) {
                    $set('treatment_id', $item->treatment_id);
                }
            });
    }

    /**
     * Agendar una sesión no es aceptar el plan: si todavía no lo firmó, se pregunta. Sin marcarlo, el plan
     * sigue esperando su firma (o queda aceptado solo cuando se haga la primera sesión).
     */
    public static function planAcceptedCheckbox(): Checkbox
    {
        return Checkbox::make('plan_accepted')
            ->label('El paciente ya aceptó el plan')
            ->helperText('Sin firmar, paga por sesión y el precio queda fijo. Si no lo marcas, el plan sigue esperando su firma.')
            ->visible(fn (Get $get) => filled($get('treatment_plan_item_id'))
                && TreatmentPlanItem::with('plan')->find($get('treatment_plan_item_id'))?->plan->accepted_at === null);
    }

    /**
     * Procedimientos pendientes de los planes vigentes del paciente. Recepción ve el tratamiento;
     * la pieza solo la ve quien puede ver lo clínico.
     *
     * @return array<int, string>
     */
    public static function planItemOptions(mixed $patientId): array
    {
        $patient = filled($patientId) ? Patient::find($patientId) : null;

        if (! $patient) {
            return [];
        }

        /** @var User $user */
        $user = auth()->user();

        return app(TreatmentPlanService::class)->workableItems($patient)
            ->mapWithKeys(fn (TreatmentPlanItem $item) => [$item->getKey() => $item->label(withTooth: $user->canSeeClinical())
                .($item->sessions_planned > 1 ? ' · sesión '.min($item->sessions_done + 1, $item->sessions_planned).' de '.$item->sessions_planned : '')
                .($item->in_payment_schedule ? ' · ya incluida en su forma de pago' : '')])
            ->all();
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
            ->getOptionLabelUsing(fn ($value) => Patient::with('account')->find($value)?->selectLabel());
    }

    /**
     * Datos mínimos del paciente nuevo, en la misma ventana de la cita.
     *
     * @return list<Field>
     */
    public static function newPatientFields(): array
    {
        return [
            TextInput::make('first_name')->label('Nombres')->required()->live(onBlur: true),
            TextInput::make('last_name')->label('Apellidos')->required()->live(onBlur: true),
            TextInput::make('phone')
                ->label('Celular')
                ->tel()
                ->helperText('Si es un familiar a cargo, usa el celular del titular.')
                ->live(onBlur: true)
                ->rule(fn () => function (string $attribute, $value, $fail) {
                    if (filled($value) && ($problem = Phone::problem((string) $value)) !== null) {
                        $fail($problem);
                    }
                }),
            PatientForm::relationshipSelect()
                ->helperText(null)
                ->default(PatientRelationship::Self->value),
            DatePicker::make('birth_date')->label('Fecha de nacimiento')->maxDate(today())->live(),
            Grid::make(['default' => 3])
                ->schema([
                    Select::make('document_type')
                        ->label('Documento')
                        ->options(DocumentType::class)
                        ->default(DocumentType::Dni->value)
                        ->selectablePlaceholder(false)
                        ->live(),
                    PatientForm::documentNumberInput()
                        ->label('Número')
                        ->columnSpan(2),
                ]),
            // Si ya está registrado, se agenda a su nombre en vez de crear otro paciente.
            PatientForm::duplicatesCheck(onPick: function (int $patientId, Set $set) {
                $set('../new_patient', false);
                $set('../patient_id', $patientId);
            }),
        ];
    }
}
