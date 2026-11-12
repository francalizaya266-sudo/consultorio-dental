<?php

namespace App\Filament\Resources\LabOrders;

use App\Enums\ExpenseCategory;
use App\Enums\LabOrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\Resources\LabOrders\Pages\ManageLabOrders;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\LabOrder;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Lo que se mandó al laboratorio dental: de qué paciente, a qué laboratorio, cuándo lo entregan y cuánto cuesta.
 * Avisa si se atrasa, si el paciente tiene cita antes de la entrega y si ya llegó y falta citarlo. Al pagarlo,
 * queda anotado en los gastos de Caja.
 */
class LabOrderResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'laboratorio';

    protected static ?string $model = LabOrder::class;

    protected static ?string $modelLabel = 'trabajo de laboratorio';

    protected static ?string $pluralModelLabel = 'trabajos de laboratorio';

    protected static ?string $navigationLabel = 'Laboratorio';

    protected static ?string $navigationParentItem = 'Pacientes';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            AppointmentForm::patientSelect()
                ->disabledOn('edit')
                ->live()
                ->columnSpanFull(),
            ...self::workFields(),
        ]);
    }

    /**
     * Lo que se anota de un trabajo: qué es, a qué laboratorio va, quién lo pidió, cuándo sale y llega, y cuánto
     * cuesta. Desde la atención el paciente ya se sabe: se pasa, con el doctor que lo atiende.
     *
     * @return list<Component>
     */
    public static function workFields(?int $patientId = null, ?int $doctorId = null): array
    {
        /** @var User $user */
        $user = auth()->user();
        $ownDoctorId = $user->managesAllAgendas() ? null : $user->doctorId();

        return [
            TextInput::make('description')
                ->label('Trabajo')
                ->placeholder('Por ejemplo: corona de zirconio, prótesis parcial, férula')
                ->required()
                ->maxLength(200)
                ->columnSpanFull(),
            TextInput::make('laboratory')
                ->label('Laboratorio')
                ->datalist(fn () => LabOrder::query()->distinct()->orderBy('laboratory')->pluck('laboratory')->all())
                ->default(fn () => LabOrder::query()->latest('id')->value('laboratory'))
                ->required()
                ->maxLength(120),
            Select::make('doctor_id')
                ->label('Lo pidió')
                ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                ->default(fn () => $ownDoctorId ?? $doctorId ?? self::onlyActiveDoctorId())
                ->disabled($ownDoctorId !== null)
                ->dehydrated()
                ->required(),
            DatePicker::make('sent_on')
                ->label('Se envió el')
                ->native(false)
                ->displayFormat('D d/m/Y')
                ->firstDayOfWeek(1)
                ->closeOnDateSelection()
                ->default(today())
                ->maxDate(today())
                ->required(),
            DatePicker::make('due_on')
                ->label('Lo entregan el')
                ->native(false)
                ->displayFormat('D d/m/Y')
                ->firstDayOfWeek(1)
                ->closeOnDateSelection()
                ->afterOrEqual('sent_on')
                ->required()
                ->live()
                ->helperText(fn (Get $get) => self::visitBeforeDelivery($patientId ?? $get('patient_id'), $get('due_on'))),
            TextInput::make('cost')
                ->label('Costo')
                ->prefix('S/')
                ->numeric()
                ->minValue(0)
                ->maxValue(999999)
                // Lo pagado ya está en Gastos con ese monto: para cambiarlo se anula el gasto en Caja.
                ->disabled(fn (?Model $record) => $record instanceof LabOrder && $record->isPaid())
                ->helperText(fn (?Model $record) => $record instanceof LabOrder && $record->isPaid()
                    ? 'Ya se pagó: para cambiar el monto, anula el gasto en Caja.'
                    : 'Lo que cobra el laboratorio. Al pagarlo queda en los gastos de Caja.'),
            Textarea::make('notes')
                ->label('Nota')
                ->placeholder('Por ejemplo: color A2, lo recoge el mensajero el viernes')
                ->rows(2)
                ->maxLength(500),
        ];
    }

    /** Al anotarlo se dice si algo pide atención: la cita del paciente quedó antes de la entrega. */
    public static function createdNotification(LabOrder $order): Notification
    {
        return ($alert = $order->alert()) === null
            ? Notification::make()->success()->title('Trabajo anotado')
            : Notification::make()->warning()->title('Trabajo anotado')->body($alert.'.')->persistent();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('patient')
                    ->label('Paciente')
                    ->state(fn (LabOrder $record) => $record->patient->full_name)
                    ->description(fn (LabOrder $record) => Doctor::severalActive() ? $record->doctor?->name : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn (Builder $patient) => $patient->search($search)))
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('description')
                    ->label('Trabajo')
                    ->description(fn (LabOrder $record) => $record->laboratory.(filled($record->notes) ? ' · '.Str::limit($record->notes, 60) : ''))
                    ->searchable(['description', 'laboratory'])
                    ->wrap(),
                TextColumn::make('due_on')
                    ->label('Entrega')
                    ->formatStateUsing(fn (LabOrder $record) => $record->due_on->translatedFormat('D j M'))
                    ->description(fn (LabOrder $record) => $record->received_on
                        ? 'Llegó el '.$record->received_on->translatedFormat('j M')
                        : 'Enviado el '.$record->sent_on->translatedFormat('j M'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (LabOrder $record) => $record->status())
                    ->color(fn (LabOrder $record) => $record->alert() !== null && $record->status() !== LabOrderStatus::Late ? 'warning' : $record->status()->getColor())
                    ->description(fn (LabOrder $record) => $record->alert())
                    ->wrap(),
                TextColumn::make('cost')
                    ->label('Costo')
                    ->money('PEN', locale: 'es_PE')
                    ->placeholder('Sin anotar')
                    ->description(fn (LabOrder $record) => match (true) {
                        $record->isPaid() => 'Pagado el '.$record->expense->spent_at->format('d/m'),
                        (float) $record->cost > 0 => 'Sin pagar',
                        default => null,
                    })
                    ->alignEnd(),
            ])
            ->recordActions([
                self::receiveAction(),
                self::bookAction(),
                ActionGroup::make([
                    self::appointmentAction(),
                    self::payAction(),
                    EditAction::make()
                        ->modalHeading('Editar trabajo de laboratorio'),
                    self::reopenAction(),
                    DeleteAction::make()
                        ->authorizationTooltip()
                        ->modalHeading('Eliminar trabajo de laboratorio')
                        ->modalDescription('Solo si se anotó por error.'),
                ]),
            ])
            ->emptyStateHeading('No hay trabajos aquí')
            ->emptyStateDescription('Anota cada trabajo que sale al laboratorio. El sistema avisa si se atrasa o si la cita del paciente es antes de la entrega.');
    }

    /** Recepción y administración llevan todos; el doctor, los que él pidió. Con la próxima cita del paciente. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['patient', 'doctor', 'expense'])
            ->whereHas('patient')
            ->withNextAppointment()
            ->when(! $user->managesAllAgendas(), fn (Builder $query) => $query->where('doctor_id', $user->doctorId()));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->needingAttention()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Atrasados, llegan hoy, con la cita antes de la entrega o ya llegaron y falta citar';
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLabOrders::route('/'),
        ];
    }

    /**
     * Al anotar o cambiar la entrega: si el paciente ya tiene cita antes de ese día, se dice ahí mismo.
     */
    public static function visitBeforeDelivery(mixed $patientId, mixed $dueOn): ?string
    {
        if (blank($patientId) || blank($dueOn)) {
            return null;
        }

        $order = new LabOrder(['patient_id' => (int) $patientId, 'due_on' => CarbonImmutable::parse($dueOn)->startOfDay()]);
        $next = $order->nextAppointmentAt();

        return $next !== null && $next->startOfDay()->lt($order->due_on)
            ? 'Tiene cita el '.$next->translatedFormat('D j M').', antes de la entrega: si es para colocarlo, mueve la cita o pide el trabajo para antes.'
            : null;
    }

    /** Llegó del laboratorio: queda recibido y, si el paciente no tiene cita, se ofrece agendarla ahí mismo. */
    private static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label('Ya llegó')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (LabOrder $record) => $record->received_on === null)
            ->authorize('update')
            ->modalHeading(fn (LabOrder $record) => 'Llegó: '.$record->description)
            ->modalDescription(fn (LabOrder $record) => $record->patient->full_name.' · '.$record->laboratory)
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Marcar recibido')
            ->schema([
                DatePicker::make('received_on')
                    ->label('Llegó el')
                    ->native(false)
                    ->displayFormat('D d/m/Y')
                    ->firstDayOfWeek(1)
                    ->closeOnDateSelection()
                    ->default(today())
                    ->minDate(fn (LabOrder $record) => $record->sent_on)
                    ->maxDate(today())
                    ->required(),
            ])
            ->action(function (array $data, LabOrder $record) {
                $record->update(['received_on' => $data['received_on']]);
                $next = $record->refresh()->nextAppointmentAt();
                $canBook = auth()->user()->can('create', Appointment::class);

                Notification::make()
                    ->success()
                    ->title('Trabajo recibido')
                    ->body($next
                        ? $record->patient->full_name.' tiene cita el '.$next->translatedFormat('D j M').' a las '.$next->format('H:i').'.'
                        : $record->patient->full_name.' no tiene cita: agéndala para colocarlo.')
                    ->actions($next || ! $canBook ? [] : [
                        Action::make('book')->label('Agendar cita')->button()->url(self::bookUrl($record)),
                    ])
                    ->persistent($next === null && $canBook)
                    ->send();
            });
    }

    /** Ya llegó y el paciente no tiene cita: a agendarla. */
    private static function bookAction(): Action
    {
        return Action::make('book')
            ->label('Agendar')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (LabOrder $record) => $record->received_on !== null && $record->alert() !== null && auth()->user()->can('create', Appointment::class))
            ->url(fn (LabOrder $record) => self::bookUrl($record));
    }

    /** La cita que quedó antes de la entrega: se abre para moverla. */
    private static function appointmentAction(): Action
    {
        return Action::make('appointment')
            ->label('Ver la cita')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (LabOrder $record) => $record->received_on === null && self::nextAppointment($record) !== null)
            ->url(fn (LabOrder $record) => AppointmentResource::getUrl('view', ['record' => self::nextAppointment($record)]));
    }

    /** Pagarle al laboratorio: queda como gasto de Caja (y en efectivo sale del cierre del día). */
    private static function payAction(): Action
    {
        return Action::make('pay')
            ->label('Anotar pago')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (LabOrder $record) => ! $record->isPaid() && auth()->user()->can('create', Expense::class))
            ->modalHeading(fn (LabOrder $record) => 'Pago a '.$record->laboratory)
            ->modalDescription(fn (LabOrder $record) => $record->description.' de '.$record->patient->full_name.'. Queda anotado en Caja, en Gastos.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Anotar pago')
            ->schema([
                TextInput::make('amount')
                    ->label('Monto')
                    ->prefix('S/')
                    ->numeric()
                    ->minValue(0.1)
                    ->maxValue(999999)
                    ->default(fn (LabOrder $record) => (float) $record->cost > 0 ? (float) $record->cost : null)
                    ->required(),
                Select::make('method')
                    ->label('Se pagó con')
                    ->options(PaymentMethod::class)
                    ->default(PaymentMethod::Cash->value)
                    ->required()
                    ->native(false)
                    ->helperText('En efectivo sale de la caja: el cierre del día lo descuenta.'),
                DateTimePicker::make('spent_at')
                    ->label('Cuándo')
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')
                    ->seconds(false)
                    ->default(fn () => now())
                    ->maxDate(fn () => now()->endOfDay())
                    ->required()
                    ->rule(fn () => RegisterPaymentAction::openDay()),
            ])
            ->action(function (array $data, LabOrder $record) {
                $expense = Expense::create([
                    'spent_at' => $data['spent_at'],
                    'category' => ExpenseCategory::Laboratory,
                    'description' => Str::limit($record->laboratory.': '.$record->description.' de '.$record->patient->full_name, 197),
                    'amount' => $data['amount'],
                    'method' => $data['method'],
                    'paid_by_user_id' => auth()->id(),
                ]);
                $record->update(['expense_id' => $expense->getKey(), 'cost' => $data['amount']]);

                Notification::make()->success()->title('Pago anotado en Gastos')->body(Money::format($data['amount']).' a '.$record->laboratory.'.')->send();
            });
    }

    /** Se marcó recibido por error: vuelve al laboratorio. */
    private static function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label('Todavía no llegó')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (LabOrder $record) => $record->received_on !== null)
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading('¿Todavía no llegó?')
            ->modalDescription('El trabajo vuelve a "En el laboratorio".')
            ->action(function (LabOrder $record) {
                $record->update(['received_on' => null]);

                Notification::make()->success()->title('Vuelve a estar en el laboratorio')->send();
            });
    }

    private static function bookUrl(LabOrder $record): string
    {
        return AppointmentResource::newUrl(array_filter(['patient' => $record->patient_id, 'doctor' => $record->doctor_id]));
    }

    /** La próxima cita del paciente si quedó antes de la entrega y quien mira la puede abrir. */
    private static function nextAppointment(LabOrder $record): ?Appointment
    {
        $next = $record->nextAppointmentAt();

        if ($next === null || ! $next->startOfDay()->lt($record->due_on)) {
            return null;
        }

        return AppointmentResource::getEloquentQuery()
            ->where('patient_id', $record->patient_id)
            ->where('starts_at', $next)
            ->first();
    }

    /** Si el consultorio tiene un solo doctor activo, ya viene elegido. */
    private static function onlyActiveDoctorId(): ?int
    {
        $ids = Doctor::query()->active()->limit(2)->pluck('id');

        return $ids->count() === 1 ? $ids->first() : null;
    }
}
