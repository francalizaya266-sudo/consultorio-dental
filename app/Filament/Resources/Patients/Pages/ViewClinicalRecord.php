<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\RelationManagers\ClinicalFilesRelationManager;
use App\Filament\Resources\Patients\RelationManagers\ConsentsRelationManager;
use App\Filament\Resources\Patients\RelationManagers\ConsultationsRelationManager;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Filament\Resources\Patients\Schemas\ClinicalRecordInfolist;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/** Historia clínica del paciente: alertas, antecedentes, odontograma, atenciones, planes, radiografías y consentimientos. */
class ViewClinicalRecord extends ViewRecord
{
    protected static string $resource = PatientResource::class;

    /**
     * Baja hasta las pestañas y se queda ahí mientras termina de cargar lo de arriba (el odontograma llega
     * después y empuja la página); en cuanto la persona toca algo, deja de moverla.
     */
    private const SCROLL_TO_TABS = <<<'JS'
        (() => {
            const tabs = document.getElementById('content.relationManagerTabs');

            if (! tabs) {
                return;
            }

            const until = Date.now() + 3000;
            const anchor = () => tabs.scrollIntoView({ block: 'start' });
            const observer = new ResizeObserver(() => Date.now() < until ? anchor() : observer.disconnect());

            ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach((name) => window.addEventListener(name, () => observer.disconnect(), { once: true, passive: true }));
            observer.observe(document.body);
            anchor();
        })()
        JS;

    /**
     * Quien llega desde un presupuesto o una atención ("Ver en su historia") viene por una pestaña de abajo:
     * la página baja sola hasta ahí en vez de dejarlo arriba, en los antecedentes.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (request()->query('relation') !== null) {
            $this->js(self::SCROLL_TO_TABS);
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Historia clínica';
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Patient $patient */
        $patient = $this->getRecord();

        return collect([
            $patient->full_name,
            $patient->age !== null ? $patient->age.' años' : null,
            $patient->document_number ? $patient->document_type?->getLabel().' '.$patient->document_number : null,
        ])->filter()->implode(' · ');
    }

    public function getBreadcrumb(): string
    {
        return 'Historia clínica';
    }

    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()->can('viewClinical', $this->getRecord()), 403);
    }

    public function infolist(Schema $schema): Schema
    {
        return ClinicalRecordInfolist::configure($schema);
    }

    protected function getAllRelationManagers(): array
    {
        return [ConsultationsRelationManager::class, TreatmentPlansRelationManager::class, ClinicalFilesRelationManager::class, ConsentsRelationManager::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Imprimir historia (PDF)')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn () => Filament::getPanel('admin')->route('print.history', ['patient' => $this->getRecord()]))
                ->openUrlInNewTab(),
            Action::make('patient')
                ->label('Ficha del paciente')
                ->icon(Heroicon::OutlinedUser)
                ->color('gray')
                ->url(fn () => PatientResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
