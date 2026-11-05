<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Actions\AppointmentActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Services\Billing\BillingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewAppointment extends ViewRecord
{
    protected static string $resource = AppointmentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Las cuotas que vencieron entran al saldo aunque el cron del servidor no haya pasado.
        app(BillingService::class)->chargeDueInstallments($this->getRecord()->patient);
    }

    /** "Cita de María Quispe", con el día y la hora debajo. */
    public function getTitle(): string|Htmlable
    {
        return 'Cita de '.$this->getRecord()->patient->full_name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $appointment = $this->getRecord();

        return ucfirst($appointment->starts_at->translatedFormat('l j \d\e F · H:i')).' · '.$appointment->treatment->name;
    }

    /** La cita es del paciente: la ruta de arriba vuelve a su ficha. */
    public function getBreadcrumbs(): array
    {
        $appointment = $this->getRecord();

        if (! PatientResource::canView($appointment->patient)) {
            return parent::getBreadcrumbs();
        }

        return [
            PatientResource::getUrl('index') => 'Pacientes',
            PatientResource::getUrl('view', ['record' => $appointment->patient]) => $appointment->patient->full_name,
            'Cita del '.$appointment->starts_at->format('d/m/Y'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...AppointmentActions::all(),
            Action::make('patient')
                ->label('Ficha del paciente')
                ->icon(Heroicon::OutlinedUser)
                ->color('gray')
                ->visible(fn () => PatientResource::canView($this->getRecord()->patient))
                ->url(fn () => PatientResource::getUrl('view', ['record' => $this->getRecord()->patient])),
            Action::make('notes')
                ->label('Editar notas')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->authorize('update')
                ->fillForm(fn () => $this->record->only(['internal_notes', 'fixed_date']))
                ->schema([
                    Toggle::make('fixed_date')->label('Fecha fija (el paciente no la mueve solo)'),
                    Textarea::make('internal_notes')->label('Notas internas')->rows(3),
                ])
                ->action(fn (array $data) => $this->record->update($data)),
        ];
    }
}
