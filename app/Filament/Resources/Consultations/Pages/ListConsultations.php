<?php

namespace App\Filament\Resources\Consultations\Pages;

use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\Notify;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Clinical\ConsultationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListConsultations extends ListRecords
{
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
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => PatientResource::getEloquentQuery()->search($search)->limit(20)->get()->mapWithKeys(fn (Patient $p) => [$p->getKey() => $p->selectLabel()]))
                    ->getOptionLabelUsing(fn ($value) => Patient::find($value)?->selectLabel())
                    ->required(),
                Select::make('treatment_id')
                    ->label('Tratamiento (opcional)')
                    ->helperText('Si tiene plantilla, se precarga.')
                    ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->pluck('name', 'id')),
            ])))
            ->action(function (array $data, Action $action) use ($patient) {
                $patient ??= Patient::find($data['patient_id']);

                if (! $patient || ! auth()->user()->can('updateClinical', $patient)) {
                    $action->failureNotificationTitle('No atiendes a este paciente. Pide a recepción que le agende una cita contigo.')->failure();

                    return;
                }

                $consultation = null;
                Notify::run(
                    function () use ($patient, $data, &$consultation) {
                        $consultation = app(ConsultationService::class)->startWithoutAppointment($patient, auth()->user(), Treatment::find($data['treatment_id'] ?? null));
                    },
                    'Atención iniciada',
                );

                if ($consultation) {
                    $action->redirect(ConsultationResource::getUrl('edit', ['record' => $consultation]));
                }
            });
    }
}
