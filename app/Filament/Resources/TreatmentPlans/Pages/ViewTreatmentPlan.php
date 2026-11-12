<?php

namespace App\Filament\Resources\TreatmentPlans\Pages;

use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\TreatmentPlans\Tables\TreatmentPlansTable;
use App\Filament\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Models\TreatmentPlan;
use App\Services\Billing\BillingService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * El presupuesto (o el plan, cuando ya lo aceptó) de un paciente, para hacerle seguimiento sin salir de ahí:
 * escribirle, agendarle lo que sigue, cobrarle lo que debe, imprimirlo o cerrarlo si dijo que no. Se arma y
 * se cambia en la historia clínica; desde aquí, quien ve lo clínico llega a ella con un toque.
 */
class ViewTreatmentPlan extends ViewRecord
{
    protected static string $resource = TreatmentPlanResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Las cuotas que vencieron entran al saldo aunque el cron del servidor no haya pasado.
        app(BillingService::class)->chargeDueInstallments($this->getPlan()->patient);
    }

    public function getTitle(): string|Htmlable
    {
        return TreatmentPlanResource::getRecordTitle($this->getPlan());
    }

    /** "S/ 750.00 · Hace 9 días sin respuesta", bajo el nombre. */
    public function getSubheading(): ?string
    {
        $plan = $this->getPlan();

        return collect([Money::format($plan->total()), TreatmentPlansTable::since($plan)])->filter()->implode(' · ');
    }

    public function getBreadcrumb(): string
    {
        return $this->getPlan()->patient->full_name;
    }

    /** Primero lo que se hace con el paciente (escribirle, agendarle, cobrarle); después, a dónde más ir. */
    protected function getHeaderActions(): array
    {
        return [
            // Al abrir WhatsApp, el seguimiento ya dice "Le escribiste hace un momento".
            TreatmentPlansTable::whatsappAction()->after(fn () => $this->reloadPlan()),
            TreatmentPlansTable::bookAction()->color('primary'),
            RegisterPaymentAction::make(fn () => $this->getPlan()->patient)
                ->hidden(fn () => $this->getPlan()->accepted_at === null || $this->getPlan()->patient->balance() <= 0),
            TreatmentPlansTable::printAction()->color('gray'),
            Action::make('clinicalRecord')
                ->label(fn () => auth()->user()->can('update', $this->getPlan()) ? 'Cambiarlo en la historia' : 'Verlo en la historia')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('view', $this->getPlan()))
                ->url(fn () => PatientResource::getUrl('clinical', ['record' => $this->getPlan()->patient_id, 'relation' => '1'])),
            Action::make('patient')
                ->label('Ficha del paciente')
                ->icon(Heroicon::OutlinedUser)
                ->color('gray')
                ->visible(fn () => PatientResource::canView($this->getPlan()->patient))
                ->url(fn () => PatientResource::getUrl('view', ['record' => $this->getPlan()->patient_id])),
            // En el menú ⋮, no como botón rojo: se usa poco y el rojo queda para lo urgente.
            ActionGroup::make([TreatmentPlansTable::declineAction()]),
        ];
    }

    /**
     * El último aviso se calcula en la consulta (así lo ordena la lista): se vuelve a traer, sobre el mismo
     * objeto, que es el que ya tienen la página, sus botones y lo que se muestra.
     */
    private function reloadPlan(): void
    {
        $fresh = $this->resolveRecord($this->getPlan()->getKey());

        $this->getPlan()->setRawAttributes($fresh->getAttributes(), sync: true)->setRelations($fresh->getRelations());
    }

    private function getPlan(): TreatmentPlan
    {
        /** @var TreatmentPlan */
        return $this->getRecord();
    }
}
