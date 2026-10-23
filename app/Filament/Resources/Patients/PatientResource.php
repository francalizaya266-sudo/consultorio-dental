<?php

namespace App\Filament\Resources\Patients;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewClinicalRecord;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\AppointmentsRelationManager;
use App\Filament\Resources\Patients\Schemas\PatientForm;
use App\Filament\Resources\Patients\Schemas\PatientInfolist;
use App\Filament\Resources\Patients\Tables\PatientsTable;
use App\Models\Patient;
use App\Models\User;
use App\Support\Phone;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PatientResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'pacientes';

    protected static ?string $model = Patient::class;

    protected static ?string $modelLabel = 'paciente';

    protected static ?string $pluralModelLabel = 'pacientes';

    protected static string|UnitEnum|null $navigationGroup = 'Pacientes';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function form(Schema $schema): Schema
    {
        return PatientForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PatientInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PatientsTable::configure($table);
    }

    /** Recepción y administración ven a todos; cada doctor, a los pacientes que atiende (con cita o atención). */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with('account')
            ->when(! $user->managesAllAgendas(), fn (Builder $q) => $q->where(fn (Builder $mine) => $mine
                ->whereHas('appointments', fn (Builder $a) => $a->where('doctor_id', $user->doctorId()))
                ->orWhereHas('consultations', fn (Builder $c) => $c->where('doctor_id', $user->doctorId()))));
    }

    public static function getRelations(): array
    {
        return [
            AppointmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPatients::route('/'),
            'create' => CreatePatient::route('/nuevo'),
            'view' => ViewPatient::route('/{record}'),
            'edit' => EditPatient::route('/{record}/editar'),
            'clinical' => ViewClinicalRecord::route('/{record}/historia'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'document_number', 'account.phone'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Patient $record */
        return array_filter([
            'DNI' => $record->document_number,
            'Celular' => $record->account ? Phone::display($record->account->phone) : null,
        ]);
    }
}
