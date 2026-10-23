<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Consultations\Pages\ListConsultations;
use App\Filament\Resources\Consultations\Tables\ConsultationsTable;
use App\Models\Patient;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Atenciones del paciente (de todos los doctores), la más reciente primero. */
class ConsultationsRelationManager extends RelationManager
{
    protected static string $relationship = 'consultations';

    protected static ?string $title = 'Atenciones';

    protected static ?string $modelLabel = 'atención';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('viewClinical', $ownerRecord);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->consultations()->count();

        return $count ? (string) $count : null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Patient $patient */
        $patient = $this->getOwnerRecord();

        return ConsultationsTable::configure($table, showPatient: false)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['doctor', 'diagnoses.icd10Code', 'patient']))
            ->filters([])
            ->headerActions([ListConsultations::withoutAppointmentAction($patient)])
            ->emptyStateDescription('La primera se crea al tocar "Atender" en su cita.');
    }
}
