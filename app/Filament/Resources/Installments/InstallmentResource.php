<?php

namespace App\Filament\Resources\Installments;

use App\Enums\MessageTemplateKey;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Installments\Pages\ManageInstallments;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\TreatmentPlanInstallment;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Cuotas por cobrar: las atrasadas, para llamar, y las que vencen esta semana. */
class InstallmentResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'cuotas';

    protected static ?string $model = TreatmentPlanInstallment::class;

    protected static ?string $modelLabel = 'cuota';

    protected static ?string $pluralModelLabel = 'cuotas por cobrar';

    protected static ?string $navigationLabel = 'Cuotas por cobrar';

    protected static string|UnitEnum|null $navigationGroup = 'Caja';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('plan.patient.account', 'plan.installments'))
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('patient')
                    ->label('Paciente')
                    ->state(fn (TreatmentPlanInstallment $record) => $record->plan->patient->full_name)
                    ->description(fn (TreatmentPlanInstallment $record) => $record->plan->patient->account ? Phone::display($record->plan->patient->account->phone) : 'Sin celular')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('plan.patient', fn (Builder $patient) => $patient->search($search)))
                    ->weight('bold'),
                TextColumn::make('label')
                    ->label('Pago')
                    ->state(fn (TreatmentPlanInstallment $record) => $record->label())
                    ->description(fn (TreatmentPlanInstallment $record) => $record->plan->title ?: 'Plan de tratamiento'),
                TextColumn::make('due_on')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->description(fn (TreatmentPlanInstallment $record) => self::when($record).($record->reminded_at ? ' · avisado el '.$record->reminded_at->format('d/m') : ''))
                    ->color(fn (TreatmentPlanInstallment $record) => $record->due_on->lt(today()) ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('due')
                    ->label('Por pagar')
                    ->state(fn (TreatmentPlanInstallment $record, ManageInstallments $livewire) => Money::format($livewire->dueOf($record)))
                    ->weight('bold'),
            ])
            ->recordActions([
                SendWhatsAppAction::compose('remind', fn (TreatmentPlanInstallment $record, ManageInstallments $livewire) => app(TreatmentPlanService::class)->installmentDraft(
                    $record,
                    $record->due_on->lt(today()) ? MessageTemplateKey::InstallmentOverdue : MessageTemplateKey::InstallmentReminder,
                    $livewire->dueOf($record),
                ))
                    ->label('Escribir')
                    ->visible(fn (TreatmentPlanInstallment $record) => $record->plan->patient->account !== null)
                    ->after(fn (TreatmentPlanInstallment $record) => $record->update(['reminded_at' => now()])),
                Action::make('patient')
                    ->label('Paciente')
                    ->icon(Heroicon::OutlinedUser)
                    ->color('gray')
                    ->url(fn (TreatmentPlanInstallment $record) => PatientResource::getUrl('view', ['record' => $record->plan->patient_id])),
            ])
            ->emptyStateHeading('Nada por cobrar aquí')
            ->emptyStateDescription('Las cuotas aparecen cuando vencen sin pagarse o cuando vencen esta semana.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInstallments::route('/'),
        ];
    }

    /** "Hace 5 días", "hoy" o "en 3 días". */
    private static function when(TreatmentPlanInstallment $installment): string
    {
        $days = (int) today()->diffInDays($installment->due_on, false);

        return match (true) {
            $days < 0 => 'Hace '.abs($days).(abs($days) === 1 ? ' día' : ' días'),
            $days === 0 => 'Hoy',
            default => 'En '.$days.($days === 1 ? ' día' : ' días'),
        };
    }
}
