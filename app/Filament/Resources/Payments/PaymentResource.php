<?php

namespace App\Filament\Resources\Payments;

use App\Enums\AppointmentStatus;
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

/** Pagos recibidos en el consultorio. Solo informativo: no se cobra en línea. */
class PaymentResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'pagos';

    protected static ?string $model = Payment::class;

    protected static ?string $modelLabel = 'pago';

    protected static ?string $pluralModelLabel = 'pagos';

    protected static ?string $navigationLabel = 'Caja';

    protected static ?int $navigationSort = 3;

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
                        $set('appointment_id', $appointmentId = self::lastAttendedId($get('patient_id')));
                        // Un $set no dispara el afterStateUpdated de la cita: el doctor y el tratamiento se llenan aquí.
                        self::fillFromAppointment($set, $appointmentId);

                        if (! $patient = Patient::find($get('patient_id'))) {
                            return;
                        }

                        // Lo que debe, listo para cobrar, con lo que suele pagar.
                        if (blank($get('amount')) && ($balance = $patient->balance()) > 0) {
                            $set('amount', $balance);
                        }
                        $set('method', $patient->usualPaymentMethod()->value);
                    })
                    ->helperText(fn (Get $get) => ($patient = Patient::find($get('patient_id'))) ? 'Saldo: '.Money::balance($patient->balance()) : null)
                    ->columnSpanFull(),
                Select::make('appointment_id')
                    ->label('Cita')
                    ->placeholder('Sin cita')
                    ->options(fn (Get $get) => self::recentAppointments($get('patient_id')))
                    ->live()
                    ->afterStateUpdated(fn (Set $set, $state) => self::fillFromAppointment($set, $state))
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

    /**
     * Citas del paciente de los últimos 30 días y de hoy, sin las canceladas ni las que faltó. Las que
     * todavía no se atendieron lo dicen, para no cobrar a cuenta de una cita que no pasó.
     *
     * @return array<int, string>
     */
    private static function recentAppointments(?string $patientId): array
    {
        if (! $patientId) {
            return [];
        }

        return Appointment::query()
            ->with('treatment')
            ->where('patient_id', $patientId)
            ->whereNotIn('status', [AppointmentStatus::Cancelled, AppointmentStatus::NoShow])
            ->whereBetween('starts_at', [now()->subDays(30), now()->endOfDay()])
            ->latest('starts_at')
            ->get()
            ->mapWithKeys(fn (Appointment $a) => [$a->id => $a->starts_at->format('d/m H:i').' · '.$a->treatment->name
                .($a->status->isChangeable() ? ' · '.mb_strtolower($a->status->getLabel()) : '')])
            ->all();
    }

    /** La última cita en que el paciente vino (atendida, en atención o en sala); ninguna si no vino. */
    private static function lastAttendedId(?string $patientId): ?int
    {
        if (! $patientId) {
            return null;
        }

        return Appointment::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', [AppointmentStatus::Completed, AppointmentStatus::InProgress, AppointmentStatus::CheckedIn])
            ->whereBetween('starts_at', [now()->subDays(30), now()->endOfDay()])
            ->latest('starts_at')
            ->value('id');
    }

    /** El doctor y el tratamiento de la cita, para que el pago cuente en sus reportes. */
    private static function fillFromAppointment(Set $set, mixed $appointmentId): void
    {
        if ($appointment = Appointment::find($appointmentId)) {
            $set('doctor_id', $appointment->doctor_id);
            $set('treatment_id', $appointment->treatment_id);
        }
    }
}
