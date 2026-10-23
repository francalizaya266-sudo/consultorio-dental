<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\PlanItemStatus;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\ClinicalException;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Actions\SignPlanAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\Schemas\TreatmentPlanForm;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Clinical\OdontogramSuggestions;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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

        return TreatmentPlanForm::configure($schema, fn () => app(OdontogramSuggestions::class)->planItems($patient->latestOdontogram), $patient);
    }

    public function table(Table $table): Table
    {
        /** @var Patient $patient */
        $patient = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['doctor', 'items', 'patient.account', 'installments', 'includedIn.patient', 'companionPlans.patient']))
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
                TextColumn::make('payment')
                    ->label('Pago')
                    ->state(fn (TreatmentPlan $record) => self::payment($record)['state'])
                    ->description(fn (TreatmentPlan $record) => self::payment($record)['detail'])
                    ->color(fn (TreatmentPlan $record) => self::payment($record)['color'])
                    ->wrap(),
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
                    ->successNotificationTitle('Plan guardado. Imprímelo o envíalo por WhatsApp al paciente.')
                    ->after(fn (TreatmentPlan $record, CreateAction $action) => self::afterSaving($record, $action->getRawData()['companion_patient_id'] ?? null)),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Plan de tratamiento')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->after(fn (TreatmentPlan $record, EditAction $action) => self::afterSaving($record, $action->getRawData()['companion_patient_id'] ?? null)),
                ViewAction::make()
                    ->modalHeading('Plan de tratamiento')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->hidden(fn (TreatmentPlan $record) => auth()->user()->can('update', $record)),
                // Firmar es el paso que falta: queda a la vista y no dentro del menú.
                SignPlanAction::make(),
                ActionGroup::make([
                    $this->bookAction(),
                    $this->scheduleAction(),
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

    /** Al guardar: el plan queda a cuenta de la campaña de su paquete y, en un 2x1, se arma el plan de la otra persona. */
    private static function afterSaving(TreatmentPlan $plan, mixed $companionId): void
    {
        // Si vino por una campaña con este paquete, el plan cuenta para ella.
        app(TreatmentPlanService::class)->linkCampaign($plan);

        $companion = filled($companionId) ? Patient::find($companionId) : null;
        $package = $companion ? $plan->twoForOnePackage() : null;

        if (! $package || $plan->companionPlans()->exists()) {
            return;
        }

        try {
            app(TreatmentPlanService::class)->addCompanion($plan, $package, $companion);
        } catch (ClinicalException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('2x1: se armó el plan de '.$companion->full_name)
            ->body('Sin costo. Queda aceptado cuando '.$plan->patient->first_name.' firme el suyo.')
            ->send();
    }

    /**
     * Cómo va el pago del plan, para verlo en la lista sin abrirlo.
     *
     * @return array{state: string, detail: ?string, color: ?string}
     */
    private static function payment(TreatmentPlan $plan): array
    {
        $pending = $plan->accepted_at ? $plan->itemsToAccept()->count() : 0;
        $toSign = $pending ? $pending.' agregado'.($pending > 1 ? 's' : '').' por firmar' : null;
        $companions = $plan->companionNames();
        $twoForOne = $companions ? '2x1 con '.$companions : null;

        if ($plan->included_in_plan_id !== null) {
            return [
                'state' => 'Incluido en el 2x1',
                'detail' => collect(['Lo paga '.$plan->includedIn?->patient?->full_name, $plan->accepted_at ? null : 'se acepta cuando firme', $toSign])->filter()->implode(' · '),
                'color' => 'success',
            ];
        }

        if ($plan->accepted_at === null) {
            return ['state' => 'Por aceptar', 'detail' => $twoForOne, 'color' => 'gray'];
        }

        if (! $plan->hasSchedule()) {
            return ['state' => $plan->payment_mode?->getLabel() ?? 'Por sesión', 'detail' => collect([$twoForOne, $toSign])->filter()->implode(' · ') ?: null, 'color' => null];
        }

        $states = collect(app(BillingService::class)->installmentStates($plan));
        $owed = round($states->whereIn('state', ['due', 'late'])->sum('due'), 2);
        $next = $states->firstWhere('state', 'upcoming');

        return [
            'state' => $plan->payment_mode->getLabel().' · '.match (true) {
                $states->contains('state', 'late') => 'atrasado '.Money::format($owed),
                $owed > 0 => 'por pagar '.Money::format($owed),
                $next === null => 'pagado',
                default => 'al día',
            },
            'detail' => collect([
                $next ? 'Próximo: '.$next['installment']->due_on->format('d/m/Y').' · '.Money::format($next['due']) : null,
                $twoForOne,
                $toSign,
            ])->filter()->implode(' · ') ?: null,
            'color' => $states->contains('state', 'late') ? 'danger' : ($owed > 0 ? 'warning' : 'success'),
        ];
    }

    private function scheduleAction(): Action
    {
        return Action::make('schedule')
            ->label('Cronograma de pagos')
            ->icon(Heroicon::OutlinedCalendar)
            ->visible(fn (TreatmentPlan $record) => $record->accepted_at !== null)
            ->modalHeading('Cronograma de pagos y firmas')
            ->modalContent(fn (TreatmentPlan $record) => view('filament.clinical.plan-schedule', [
                'paymentText' => app(TreatmentPlanService::class)->paymentText($record),
                'states' => app(BillingService::class)->installmentStates($record),
                'signatures' => $record->signatures,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar');
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
            ->modalDescription(fn (TreatmentPlan $record) => 'No se borra: queda en la historia como cancelado, con lo que se llegó a hacer.'
                .($record->hasSchedule() ? ' Las cuotas que faltan ya no se cargan.' : ''))
            ->modalSubmitActionLabel('Cancelar plan')
            ->schema(fn (TreatmentPlan $record) => [
                Textarea::make('reason')
                    ->label('Motivo (opcional)')
                    ->placeholder('Ej.: el paciente prefirió otra opción.')
                    ->rows(2),
                Toggle::make('charge_missing')
                    ->label(fn () => 'Cargar lo hecho que falta cobrar ('.Money::format(self::missing($record)).')')
                    ->helperText(fn () => 'Hecho: '.Money::format(app(TreatmentPlanService::class)->scheduleProgress($record)['done'])
                        .' · cargado con su forma de pago: '.Money::format(app(TreatmentPlanService::class)->scheduleProgress($record)['charged']))
                    ->default(true)
                    ->visible(fn () => self::missing($record) > 0),
            ])
            ->action(function (TreatmentPlan $record, array $data) {
                /** @var User $user */
                $user = auth()->user();

                app(TreatmentPlanService::class)->cancel($record, $data['reason'] ?? null, ($data['charge_missing'] ?? false) ? $user : null);
                Notification::make()->success()->title('Plan cancelado')->send();
            });
    }

    /** Si el plan se pagaba con cronograma: lo hecho que sus pagos no alcanzaron a cubrir. */
    private static function missing(TreatmentPlan $plan): float
    {
        if (! $plan->hasSchedule()) {
            return 0.0;
        }

        $progress = app(TreatmentPlanService::class)->scheduleProgress($plan);

        return max(0, round($progress['done'] - $progress['charged'], 2));
    }
}
