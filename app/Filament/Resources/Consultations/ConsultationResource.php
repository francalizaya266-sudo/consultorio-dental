<?php

namespace App\Filament\Resources\Consultations;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Consultations\Pages\ListConsultations;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Resources\Consultations\Schemas\ConsultationForm;
use App\Filament\Resources\Consultations\Schemas\ConsultationInfolist;
use App\Filament\Resources\Consultations\Tables\ConsultationsTable;
use App\Models\Consultation;
use App\Models\Prescription;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Atenciones clínicas. Solo las ven los doctores y el administrador odontólogo (Ley 29733). */
class ConsultationResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'atenciones';

    protected static ?string $model = Consultation::class;

    protected static ?string $modelLabel = 'atención';

    protected static ?string $pluralModelLabel = 'atenciones';

    protected static string|UnitEnum|null $navigationGroup = 'Pacientes';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function form(Schema $schema): Schema
    {
        return ConsultationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConsultationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConsultationsTable::configure($table);
    }

    /** El administrador odontólogo ve todas; cada doctor, las de los pacientes que atiende. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['patient.medicalBackground', 'doctor', 'diagnoses.icd10Code', 'appointment.treatment'])
            ->when(! $user->isAdmin(), fn (Builder $query) => $query->whereHas('patient', fn (Builder $patient) => $patient
                ->whereHas('appointments', fn (Builder $a) => $a->where('doctor_id', $user->doctorId()))
                ->orWhereHas('consultations', fn (Builder $c) => $c->where('doctor_id', $user->doctorId()))));
    }

    /** Atenciones propias que quedaron sin terminar. */
    public static function getNavigationBadge(): ?string
    {
        /** @var User $user */
        $user = auth()->user();
        $open = $user->doctorId()
            ? Consultation::query()->where('doctor_id', $user->doctorId())->whereNull('closed_at')->count()
            : 0;

        return $open ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Atenciones sin terminar';
    }

    public static function getRecordTitle(?Model $record): string
    {
        /** @var Consultation|null $record */
        return $record ? 'Atención de '.$record->patient->full_name : 'Atención';
    }

    /** Abre la atención para escribir si se puede; si no, en modo lectura. */
    public static function urlFor(Consultation $consultation): string
    {
        return static::getUrl(auth()->user()->can('update', $consultation) ? 'edit' : 'view', ['record' => $consultation]);
    }

    public static function prescriptionPrintUrl(Prescription $prescription): string
    {
        return Filament::getPanel('admin')->route('print.prescription', ['prescription' => $prescription]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsultations::route('/'),
            'edit' => EditConsultation::route('/{record}'),
            'view' => ViewConsultation::route('/{record}/ver'),
        ];
    }
}
