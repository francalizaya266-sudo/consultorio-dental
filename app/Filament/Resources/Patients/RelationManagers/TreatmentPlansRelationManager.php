<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\PlanItemStatus;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\Schemas\TreatmentPlanForm;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Planes de tratamiento del paciente con su presupuesto y avance. */
class TreatmentPlansRelationManager extends RelationManager
{
    protected static string $relationship = 'treatmentPlans';

    protected static ?string $title = 'Planes de tratamiento';

    protected static ?string $modelLabel = 'plan de tratamiento';

    protected static ?string $pluralModelLabel = 'planes de tratamiento';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('viewClinical', $ownerRecord);
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $open = $ownerRecord->treatmentPlans()->open()->count();

        return $open ? $open.' vigente'.($open > 1 ? 's' : '') : null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Patient $patient */
        $patient = $this->getOwnerRecord();

        return TreatmentPlanForm::configure($schema, fn () => app(TreatmentPlanService::class)->suggestFromOdontogram($patient->latestOdontogram));
    }

    public function table(Table $table): Table
    {
        /** @var Patient $patient */
        $patient = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['doctor', 'items', 'patient.account']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->date('d/m/Y'),
                TextColumn::make('title')
                    ->label('Plan')
                    ->placeholder('Plan de tratamiento')
                    ->weight('bold')
                    ->description(fn (TreatmentPlan $record) => $record->items
                        ->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)
                        ->map(fn (TreatmentPlanItem $item) => $item->label())
                        ->implode(' · '))
                    ->wrap(),
                TextColumn::make('progress')
                    ->label('Avance')
                    ->state(function (TreatmentPlan $record) {
                        $items = $record->items->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled);

                        return $items->where('status', PlanItemStatus::Done)->count().' de '.$items->count().' hechos';
                    }),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (TreatmentPlan $record) => Money::format($record->total())),
                TextColumn::make('doctor.name')
                    ->label('Doctor')
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->recordAction(fn (TreatmentPlan $record) => auth()->user()->can('update', $record) ? 'edit' : 'view')
            ->headerActions([
                CreateAction::make()
                    ->label('Nuevo plan')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading('Nuevo plan de tratamiento')
                    ->modalDescription('Ya vienen los hallazgos en rojo del último odontograma con el precio del catálogo. Ajusta lo que haga falta y guarda.')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitActionLabel('Guardar plan')
                    ->createAnother(false)
                    ->visible(fn () => auth()->user()->can('updateClinical', $patient))
                    ->mutateDataUsing(fn (array $data) => [...$data, 'doctor_id' => auth()->user()->doctorId(), 'status' => TreatmentPlanStatus::Draft])
                    ->successNotificationTitle('Plan guardado. Imprímelo o envíalo por WhatsApp al paciente.'),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Plan de tratamiento')
                    ->modalWidth(Width::FiveExtraLarge),
                ViewAction::make()
                    ->modalHeading('Plan de tratamiento')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->hidden(fn (TreatmentPlan $record) => auth()->user()->can('update', $record)),
                ActionGroup::make([
                    $this->acceptAction(),
                    $this->bookAction(),
                    Action::make('print')
                        ->label('Imprimir presupuesto')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->url(fn (TreatmentPlan $record) => self::printUrl($record))
                        ->openUrlInNewTab(),
                    SendWhatsAppAction::compose('sendWhatsApp', fn (TreatmentPlan $record) => app(TreatmentPlanService::class)->whatsappDraft($record))
                        ->label('Enviar presupuesto por WhatsApp')
                        ->visible(fn (TreatmentPlan $record) => $record->patient->account !== null)
                        ->after(function (TreatmentPlan $record) {
                            if (auth()->user()->can('update', $record)) {
                                app(TreatmentPlanService::class)->markProposed($record);
                            }
                        }),
                    $this->cancelAction(),
                ]),
            ])
            ->emptyStateHeading('Sin planes de tratamiento')
            ->emptyStateDescription('Toca "Nuevo plan": se arma solo con lo que está en rojo en el odontograma.');
    }

    public static function printUrl(TreatmentPlan $plan): string
    {
        return Filament::getPanel('admin')->route('print.plan', ['plan' => $plan]);
    }

    private function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('El paciente aceptó')
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('success')
            ->visible(fn (TreatmentPlan $record) => in_array($record->status, [TreatmentPlanStatus::Draft, TreatmentPlanStatus::Proposed], true))
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading('¿El paciente aceptó el plan?')
            ->modalDescription('Queda como aceptado con la fecha de hoy. Sus sesiones se pueden agendar desde aquí.')
            ->modalSubmitActionLabel('Sí, aceptó')
            ->action(function (TreatmentPlan $record) {
                app(TreatmentPlanService::class)->accept($record);
                Notification::make()->success()->title('Plan aceptado')->send();
            });
    }

    /** Agenda la próxima sesión: la cita queda unida al procedimiento y al atenderla se marca sola. */
    private function bookAction(): Action
    {
        return Action::make('book')
            ->label('Agendar sesión')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (TreatmentPlan $record) => $record->isOpen()
                && $record->items->contains(fn (TreatmentPlanItem $item) => $item->isOpen())
                && auth()->user()->can('create', Appointment::class))
            ->url(function (TreatmentPlan $record) {
                $item = $record->items->first(fn (TreatmentPlanItem $item) => $item->isOpen());

                return AppointmentResource::getUrl('create', array_filter([
                    'patient' => $record->patient_id,
                    'doctor' => $record->doctor_id,
                    'treatment' => $item?->treatment_id,
                    'plan_item' => $item?->getKey(),
                ]));
            });
    }

    private function cancelAction(): Action
    {
        return Action::make('cancelPlan')
            ->label('Cancelar plan')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (TreatmentPlan $record) => $record->isOpen())
            ->authorize('update')
            ->modalHeading('¿Cancelar este plan?')
            ->modalDescription('No se borra: queda en la historia como cancelado, con lo que se llegó a hacer.')
            ->modalSubmitActionLabel('Cancelar plan')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo (opcional)')
                    ->placeholder('Ej.: el paciente prefirió otra opción.')
                    ->rows(2),
            ])
            ->action(function (TreatmentPlan $record, array $data) {
                app(TreatmentPlanService::class)->cancel($record, $data['reason'] ?? null);
                Notification::make()->success()->title('Plan cancelado')->send();
            });
    }
}
