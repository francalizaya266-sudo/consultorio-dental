<?php

namespace App\Filament\Resources\TreatmentPlans;

use App\Enums\AppointmentStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\PlanItemStatus;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\TreatmentPlans\Pages\ListTreatmentPlans;
use App\Filament\Resources\TreatmentPlans\Tables\TreatmentPlansTable;
use App\Models\Appointment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Models\WhatsappMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los presupuestos y planes de todos los pacientes, para hacerles seguimiento: cuánto suman, cuánto
 * llevan sin respuesta, quién tiene su próxima cita y a quién ya se le escribió. Cada plan se arma y
 * se cambia en la historia clínica; aquí recepción solo ve lo que necesita para llamar.
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

    public static function table(Table $table): Table
    {
        return TreatmentPlansTable::configure($table);
    }

    /**
     * Cada doctor ve los que armó. Trae el total (sin lo cancelado), la próxima cita del paciente
     * y el último WhatsApp sobre su plan, calculados en la base para poder ordenar y sumar.
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['patient.account', 'doctor', 'items.package', 'includedIn.patient'])
            ->whereHas('patient')
            ->when(! $user->managesAllAgendas(), fn (Builder $mine) => $mine->where('doctor_id', $user->doctorId() ?? 0))
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
        ];
    }
}
