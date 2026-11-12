<?php

namespace App\Filament\Resources\Appointments;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\Resources\Appointments\Schemas\AppointmentInfolist;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Clinical\TreatmentPlanService;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppointmentResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'citas';

    protected static ?string $model = Appointment::class;

    protected static ?string $modelLabel = 'cita';

    protected static ?string $pluralModelLabel = 'citas';

    protected static ?string $navigationParentItem = 'Agenda';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    /** Lo que puede traer puesto la ventana "Nueva cita" cuando se abre con un enlace. */
    public const NEW_APPOINTMENT_ARGUMENTS = ['patient', 'doctor', 'treatment', 'plan_item', 'date', 'time'];

    public static function form(Schema $schema): Schema
    {
        return AppointmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AppointmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppointmentsTable::configure($table);
    }

    /** Cada doctor ve solo su agenda. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['patient.account', 'doctor', 'treatment'])
            ->when(! $user->managesAllAgendas(), fn (Builder $q) => $q->where('doctor_id', $user->doctorId()));
    }

    /**
     * La pestaña "Por confirmar" con esas fechas ya puestas en el filtro: la lista trae las mismas citas que cuenta
     * quien enlaza (la tarjeta de Hoy, el aviso de citas sin confirmar).
     */
    public static function toConfirmUrl(CarbonInterface $from, CarbonInterface $until, bool $onlyToCall = false): string
    {
        return self::getUrl('index', ['tab' => 'pending', 'filters' => array_filter([
            'dates' => ['from' => $from->toDateString(), 'until' => $until->toDateString()],
            'to_call' => $onlyToCall ? ['isActive' => true] : null,
        ])]);
    }

    /**
     * La próxima cita del paciente, ya con la siguiente sesión pendiente de su plan y su tratamiento: así
     * nace como sesión del plan, y al atenderla se cobra al precio acordado y el plan avanza. Con $item, esa
     * sesión (por ejemplo, la primera de un presupuesto que todavía no acepta: al agendar se pregunta).
     */
    public static function nextVisitUrl(Patient $patient, ?int $doctorId, ?CarbonInterface $date = null, ?TreatmentPlanItem $item = null): string
    {
        $next = $item ?? app(TreatmentPlanService::class)->nextToSchedule($patient);

        return self::newUrl([
            'patient' => $patient->getKey(),
            'doctor' => $next?->plan->doctor_id ?? $doctorId,
            'plan_item' => $next?->getKey(),
            'treatment' => $next?->treatment_id,
            'date' => $date?->toDateString(),
        ]);
    }

    /**
     * "Nueva cita" es una sola: la ventana sobre la agenda. Este enlace la abre desde cualquier otra pantalla con
     * lo que ya se sabe (el paciente, el doctor, el tratamiento, la sesión de su plan, el día, la hora) y con ese
     * día a la vista detrás. Al agendar se vuelve a la pantalla desde donde se abrió.
     *
     * @param  array{patient?: int|string|null, doctor?: int|string|null, treatment?: int|string|null, plan_item?: int|string|null, date?: string|null, time?: string|null}  $with
     */
    public static function newUrl(array $with = []): string
    {
        $known = collect(self::NEW_APPOINTMENT_ARGUMENTS)
            ->mapWithKeys(fn (string $key) => [$key => $with[$key] ?? null])
            ->filter(fn (mixed $value) => is_scalar($value) && filled($value))
            ->all();

        return Agenda::getUrl(array_filter([
            'date' => $known['date'] ?? null,
            'action' => 'createAppointment',
            'actionArguments' => $known,
        ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointments::route('/'),
            'create' => CreateAppointment::route('/nueva'),
            'view' => ViewAppointment::route('/{record}'),
        ];
    }
}
