<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Concerns\HasPatientSummaryActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Filament\Support\PatientSummary;
use App\Models\Patient;
use App\Services\Billing\BillingService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPatient extends ViewRecord
{
    use HasPatientSummaryActions;

    protected static string $resource = PatientResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Las cuotas que vencieron entran al saldo aunque el cron del servidor no haya pasado.
        app(BillingService::class)->chargeDueInstallments($this->getRecord());
    }

    protected function summaryPatient(): Patient
    {
        return $this->getRecord();
    }

    /** "Anular" en un pago de la línea de tiempo: el mismo de Caja, con su motivo y el día cerrado. */
    public function voidPaymentAction(): Action
    {
        return PaymentsTable::voidAction()
            ->name('voidPayment')
            ->record(fn (array $arguments) => $this->getRecord()->payments()->find($arguments['payment'] ?? null));
    }

    /** Edad, documento, distrito y desde cuándo viene, bajo el nombre. */
    public function getSubheading(): ?string
    {
        return PatientSummary::subheading($this->getRecord());
    }

    /** Lo de cada uno va en azul: al doctor, la historia; a recepción y administración, la cita. */
    protected function getHeaderActions(): array
    {
        $clinicFirst = ! auth()->user()->managesAllAgendas();

        return [
            Action::make('clinicalRecord')
                ->label('Historia clínica')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->color($clinicFirst ? 'primary' : 'gray')
                ->visible(fn () => auth()->user()->can('viewClinical', $this->getRecord()))
                ->url(fn () => PatientResource::getUrl('clinical', ['record' => $this->getRecord()])),
            RegisterPaymentAction::make(fn () => $this->getRecord()),
            Action::make('book')
                ->label('Nueva cita')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color($clinicFirst ? 'gray' : 'primary')
                ->url(fn () => AppointmentResource::newUrl(['patient' => $this->getRecord()->getKey()])),
            EditAction::make()
                ->color('gray'),
        ];
    }
}
