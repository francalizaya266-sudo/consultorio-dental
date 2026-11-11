<?php

namespace App\Filament\Resources\Consultations\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\Notify;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Clinical\ConsultationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ListConsultations extends ListRecords
{
    use HasNoBreadcrumbs;

    protected static string $resource = ConsultationResource::class;

    protected ?string $subheading = 'Lo normal es abrir la atención con "Atender" en la cita. Aquí puedes atender a alguien sin cita.';

    protected function getHeaderActions(): array
    {
        return [self::withoutAppointmentAction()];
    }

    /** Atención sin cita: urgencia o paciente que pasó directo. */
    public static function withoutAppointmentAction(?Patient $patient = null): Action
    {
        return Action::make('withoutAppointment')
            ->label($patient ? 'Nueva atención' : 'Atención sin cita')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn () => auth()->user()->can('create', Consultation::class) && (! $patient || auth()->user()->can('updateClinical', $patient)))
            ->modalHeading('Nueva atención')
            ->modalSubmitActionLabel('Empezar')
            ->schema(array_values(array_filter([
                $patient ? null : Select::make('patient_id')
                    ->label('Paciente')
                    ->placeholder('Busca por nombre, DNI o celular')
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search) => PatientResource::getEloquentQuery()->search($search)->limit(20)->get()->mapWithKeys(fn (Patient $p) => [$p->getKey() => $p->selectLabel()]))
                    ->getOptionLabelUsing(fn ($value) => Patient::find($value)?->selectLabel())
                    ->required(),
                // Tiene cita hoy: lo normal es atender esa, para que no quede como falta y su plan avance.
                Toggle::make('use_appointment')
                    ->label(fn (Get $get) => 'Atender su cita de hoy'.(($today = self::todaysAppointment($patient?->getKey() ?? $get('patient_id'))) ? ' de las '.$today->starts_at->format('H:i') : ''))
                    ->helperText('Así la cita queda atendida y avanza lo de su plan. Desmárcalo solo si es una urgencia aparte y vendrá igual a su cita.')
                    ->default(true)
                    ->visible(fn (Get $get) => self::todaysAppointment($patient?->getKey() ?? $get('patient_id')) !== null)
                    ->live(),
                Select::make('treatment_id')
                    ->label('Tratamiento (opcional)')
                    ->helperText('Llega marcado en "Lo que se hizo" para cobrarse al terminar. Si tiene plantilla, se precarga.')
                    ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                    ->hidden(fn (Get $get) => $get('use_appointment') && self::todaysAppointment($patient?->getKey() ?? $get('patient_id')) !== null),
            ])))
            ->action(function (array $data, Action $action) use ($patient) {
                $patient ??= Patient::find($data['patient_id']);

                if (! $patient || ! auth()->user()->can('updateClinical', $patient)) {
                    $action->failureNotificationTitle('No atiendes a este paciente. Pide a recepción que le agende una cita contigo.')->failure();

                    return;
                }

                $consultations = app(ConsultationService::class);
                $consultation = null;
                $appointment = ($data['use_appointment'] ?? false) ? self::todaysAppointment($patient->getKey()) : null;
                $resumes = $appointment === null && $consultations->unfinishedWithoutAppointment($patient, auth()->user()) !== null;
                Notify::run(
                    function () use ($patient, $data, $consultations, $appointment, &$consultation) {
                        $consultation = $appointment
                            ? $consultations->startFromAppointment($appointment, auth()->user())
                            : $consultations->startWithoutAppointment($patient, auth()->user(), Treatment::find($data['treatment_id'] ?? null));
                    },
                    match (true) {
                        $appointment !== null => 'Se atiende su cita de hoy de las '.$appointment->starts_at->format('H:i'),
                        $resumes => 'Ya tenía una atención sin terminar: se retoma esa',
                        default => 'Atención iniciada',
                    },
                );

                if ($consultation) {
                    Notify::heldMedications($consultations->heldMedications());
                    $action->redirect(ConsultationResource::getUrl('edit', ['record' => $consultation]));
                }
            });
    }

    /** La cita de hoy del paciente con quien atiende, si todavía no se terminó: la que toca atender en vez de abrir otra. */
    public static function todaysAppointment(mixed $patientId): ?Appointment
    {
        $doctorId = auth()->user()->doctorId();

        if (blank($patientId) || $doctorId === null) {
            return null;
        }

        return Appointment::query()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->whereIn('status', AppointmentStatus::open())
            ->whereDate('starts_at', today())
            ->orderBy('starts_at')
            ->first();
    }
}
