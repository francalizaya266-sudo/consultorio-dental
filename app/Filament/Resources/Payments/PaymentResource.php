<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentMethod;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Pagos recibidos en el consultorio. Solo informativo: no se cobra en línea. */
class PaymentResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'pagos';

    protected static ?string $model = Payment::class;

    protected static ?string $modelLabel = 'pago';

    protected static ?string $pluralModelLabel = 'pagos';

    protected static string|UnitEnum|null $navigationGroup = 'Caja';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('patient_id')
                    ->label('Paciente')
                    ->placeholder('Busca por nombre, DNI o celular')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search) => Patient::query()
                        ->search($search)
                        ->with('account')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Patient $p) => [$p->id => $p->selectLabel()]))
                    ->getOptionLabelUsing(fn ($value) => Patient::with('account')->find($value)?->selectLabel())
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        $set('appointment_id', self::lastAppointmentId($get('patient_id')));

                        // Lo que debe, listo para cobrar.
                        if (blank($get('amount')) && ($balance = Patient::find($get('patient_id'))?->balance()) > 0) {
                            $set('amount', $balance);
                        }
                    })
                    ->helperText(fn (Get $get) => ($patient = Patient::find($get('patient_id'))) ? 'Saldo: '.Money::balance($patient->balance()) : null)
                    ->columnSpanFull(),
                Select::make('appointment_id')
                    ->label('Cita')
                    ->placeholder('Sin cita')
                    ->options(fn (Get $get) => self::recentAppointments($get('patient_id')))
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state) {
                        if ($appointment = Appointment::find($state)) {
                            $set('doctor_id', $appointment->doctor_id);
                            $set('treatment_id', $appointment->treatment_id);
                        }
                    })
                    ->columnSpanFull(),
                TextInput::make('amount')
                    ->label('Monto')
                    ->prefix('S/')
                    ->numeric()
                    ->minValue(0.01)
                    ->required(),
                DateTimePicker::make('paid_at')
                    ->label('Fecha')
                    ->seconds(false)
                    ->default(now())
                    ->maxDate(now()->endOfDay())
                    ->required(),
                ToggleButtons::make('method')
                    ->label('Medio de pago')
                    ->options(PaymentMethod::class)
                    ->default(PaymentMethod::Cash->value)
                    ->inline()
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('reference')
                    ->label('N.º de operación')
                    ->maxLength(100)
                    ->hidden(fn (Get $get) => in_array($get('method'), [null, PaymentMethod::Cash->value, PaymentMethod::Cash], true)),
                Select::make('treatment_id')
                    ->label('Tratamiento')
                    ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                    ->searchable(),
                Select::make('doctor_id')
                    ->label('Doctor')
                    ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id')),
                Textarea::make('notes')
                    ->label('Nota')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['patient', 'treatment', 'doctor', 'receivedBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePayments::route('/'),
        ];
    }

    /** @return array<int, string> Citas del paciente de los últimos 30 días y de hoy. */
    private static function recentAppointments(?string $patientId): array
    {
        if (! $patientId) {
            return [];
        }

        return Appointment::query()
            ->with('treatment')
            ->where('patient_id', $patientId)
            ->whereBetween('starts_at', [now()->subDays(30), now()->endOfDay()])
            ->latest('starts_at')
            ->get()
            ->mapWithKeys(fn (Appointment $a) => [$a->id => $a->starts_at->format('d/m H:i').' · '.$a->treatment->name])
            ->all();
    }

    private static function lastAppointmentId(?string $patientId): ?int
    {
        return array_key_first(self::recentAppointments($patientId));
    }
}
