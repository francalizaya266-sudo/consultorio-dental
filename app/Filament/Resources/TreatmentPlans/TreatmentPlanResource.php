<?php

namespace App\Filament\Resources\TreatmentPlans;

use App\Enums\AppointmentStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\PlanItemStatus;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\TreatmentPlans\Pages\ListTreatmentPlans;
use App\Filament\Resources\TreatmentPlans\Pages\ViewTreatmentPlan;
use App\Filament\Resources\TreatmentPlans\Schemas\TreatmentPlanInfolist;
use App\Filament\Resources\TreatmentPlans\Tables\TreatmentPlansTable;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Models\WhatsappMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Los presupuestos y planes de todos los pacientes, para hacerles seguimiento: cuánto suman, cuánto
 * llevan sin respuesta, quién tiene su próxima cita y a quién ya se le escribió. Cada uno se abre en su
 * propia página (lo que incluye, cómo va su pago, su seguimiento). Se arma y se cambia en la historia
 * clínica; aquí recepción solo ve lo que necesita para llamar.
 */
class TreatmentPlanResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'presupuestos';

    protected static ?string $model = TreatmentPlan::class;

    protected static ?string $modelLabel = 'presupuesto';

    protected static ?string $pluralModelLabel = 'presupuestos y planes';

    protected static ?string $navigationLabel = 'Presupuestos y planes';

    protected static ?string $navigationParentItem = 'Pacientes';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    /** Avisos que cuentan como "ya le escribiste" sobre su presupuesto o su plan. */
    public const CONTACT_TEMPLATES = [MessageTemplateKey::TreatmentPlan, MessageTemplateKey::PlanFollowUp, MessageTemplateKey::PlanNextSession];

    /** No es la lista de la historia clínica: tiene su propio permiso, y sin datos clínicos para quien no los ve. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewBudgets', TreatmentPlan::class) ?? false;
    }

    /** Un presupuesto lo abre quien le hace seguimiento o el doctor que atiende al paciente. */
    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('viewBudget', $record) ?? false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return TreatmentPlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TreatmentPlansTable::configure($table);
    }

    public static function getRecordTitle(?Model $record): string
    {
        /** @var TreatmentPlan|null $record */
        return $record ? ($record->accepted_at ? 'Plan de ' : 'Presupuesto de ').$record->patient->full_name : 'Presupuesto';
    }

    /** En la lista, cada doctor ve los que armó. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return self::withFollowUp(parent::getEloquentQuery())
            ->when(! $user->managesAllAgendas(), fn (Builder $mine) => $mine->where('doctor_id', $user->doctorId() ?? 0));
    }

    /**
     * La página de un presupuesto también la abre el doctor que atiende al paciente aunque lo haya armado
     * otro (como en su historia): quién entra lo decide la política, no la lista.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return self::withFollowUp(parent::getEloquentQuery());
    }

    /**
     * Los presupuestos de un paciente que quien mira puede abrir, para su ficha: los vigentes primero.
     *
     * @return Collection<int, TreatmentPlan>
     */
    public static function forPatient(Patient $patient): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return self::getRecordRouteBindingEloquentQuery()
            ->with('installments')
            ->whereBelongsTo($patient)
            ->latest('id')
            ->get()
            ->filter(fn (TreatmentPlan $plan) => $user->can('viewBudget', $plan))
            ->sortBy(fn (TreatmentPlan $plan) => $plan->isOpen() ? 0 : 1)
            ->values();
    }

    /**
     * Trae el total (sin lo cancelado), la próxima cita del paciente y el último WhatsApp sobre su plan,
     * calculados en la base para poder ordenar y sumar.
     */
    private static function withFollowUp(Builder $query): Builder
    {
        return $query
            ->with(['patient.account', 'doctor', 'items.package', 'includedIn.patient'])
            ->whereHas('patient')
            ->addSelect([
                'total_amount' => TreatmentPlanItem::query()
                    ->selectRaw('coalesce(sum(quantity * unit_price - discount), 0)')
                    ->whereColumn('treatment_plan_id', 'treatment_plans.id')
                    ->where('status', '!=', PlanItemStatus::Cancelled),
                'next_appointment_at' => Appointment::query()
                    ->selectRaw('min(starts_at)')
                    ->whereColumn('patient_id', 'treatment_plans.patient_id')
                    ->where('starts_at', '>=', today())
                    ->whereIn('status', AppointmentStatus::occupying()),
                'last_contacted_at' => WhatsappMessage::query()
                    ->selectRaw('max(sent_at)')
                    ->whereColumn('patient_id', 'treatment_plans.patient_id')
                    ->where('status', MessageStatus::Sent)
                    ->whereIn('template_key', self::CONTACT_TEMPLATES),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTreatmentPlans::route('/'),
            'view' => ViewTreatmentPlan::route('/{record}'),
        ];
    }
}
