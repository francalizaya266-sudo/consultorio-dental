<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\RelationManagers\ConsultationsRelationManager;
use App\Filament\Resources\Patients\Schemas\ClinicalRecordInfolist;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/** Historia clínica del paciente: alertas, antecedentes, odontograma y atenciones. */
class ViewClinicalRecord extends ViewRecord
{
    protected static string $resource = PatientResource::class;

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
        return [ConsultationsRelationManager::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('patient')
                ->label('Ficha del paciente')
                ->icon(Heroicon::OutlinedUser)
                ->color('gray')
                ->url(fn () => PatientResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
