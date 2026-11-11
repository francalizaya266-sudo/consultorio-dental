<?php

namespace App\Filament\Resources\TreatmentPlans\Tables;

use App\Enums\MessageTemplateKey;
use App\Enums\PlanItemStatus;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\RelationManagers\TreatmentPlansRelationManager;
use App\Models\Doctor;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\Dashboard\FollowUps;
use App\Support\Money;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Cada presupuesto con su paciente, lo que incluye, su estado, cuánto suma y en qué va el seguimiento. */
class TreatmentPlansTable
{
    public static function configure(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();
        $clinical = $user->canSeeClinical();

        return $table
            ->stackedOnMobile()
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('patient')
                    ->label('Paciente')
                    ->state(fn (TreatmentPlan $record) => $record->patient->full_name)
                    ->description(fn (TreatmentPlan $record) => $record->patient->account ? Phone::display($record->patient->account->phone) : 'Sin celular')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn (Builder $patient) => $patient->search($search)))
                    ->weight('bold'),
                TextColumn::make('items')
                    ->label('Incluye')
                    // Recepción ve el procedimiento, no la pieza (Ley 29733).
                    ->state(fn (TreatmentPlan $record) => self::summary($record, withTooth: $clinical))
                    ->description(fn (TreatmentPlan $record) => collect([
                        $record->included_in_plan_id !== null ? 'En el 2x1 de '.$record->includedIn?->patient?->full_name : null,
                        $clinical ? $record->title : null,
                        // El doctor ya sabe que son suyos.
                        $user->managesAllAgendas() ? $record->doctor?->name : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->description(fn (TreatmentPlan $record) => collect([
                        self::since($record),
                        $record->last_contacted_at ? 'le escribiste '.CarbonImmutable::parse($record->last_contacted_at)->diffForHumans() : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->wrap(),
                TextColumn::make('total_amount')
                    ->label('Monto')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state))
                    ->sortable()
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state) => Money::format((float) $state))),
                TextColumn::make('next_appointment_at')
                    ->label('Próxima cita')
                    ->formatStateUsing(fn ($state) => CarbonImmutable::parse($state)->translatedFormat('D j M, H:i'))
                    ->placeholder(fn (TreatmentPlan $record) => self::waitsForAppointment($record) ? 'Sin cita' : '—')
                    ->color(fn (TreatmentPlan $record) => self::waitsForAppointment($record) ? 'warning' : null)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('doctor_id')
                    ->label('Doctor')
                    ->options(fn () => Doctor::query()->orderBy('sort_order')->pluck('name', 'id'))
                    ->visible($user->managesAllAgendas()),
            ])
            ->recordActions([
                self::whatsappAction(),
                Action::make('book')
                    ->label('Nueva cita')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('gray')
                    ->visible(fn (TreatmentPlan $record) => self::waitsForAppointment($record) && AppointmentResource::canCreate())
                    ->url(fn (TreatmentPlan $record) => AppointmentResource::getUrl('create', [
                        'patient' => $record->patient_id,
                        'plan_item' => self::openItems($record)->first()?->getKey(),
                    ])),
                ActionGroup::make([
                    // Quien no ve la historia imprime solo el presupuesto, sin pieza ni diagnóstico.
                    Action::make('print')
                        ->label('Ver e imprimir')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->url(fn (TreatmentPlan $record) => TreatmentPlansRelationManager::printUrl($record), shouldOpenInNewTab: true),
                    self::declineAction(),
                ]),
            ])
            // Tocar la fila abre el plan (o el paciente, para quien no ve lo clínico).
            ->recordUrl(fn (TreatmentPlan $record) => $user->can('view', $record)
                ? PatientResource::getUrl('clinical', ['record' => $record->patient_id, 'relation' => '1'])
                : PatientResource::getUrl('view', ['record' => $record->patient_id]))
            ->emptyStateHeading('No hay presupuestos aquí')
            ->emptyStateDescription('Los presupuestos se arman en la historia clínica del paciente, al terminar su evaluación.');
    }

    /**
     * El aviso que toca: "¿pudiste revisar tu presupuesto?" mientras no responde, o "¿te reservamos
     * tu próxima cita?" si lo aceptó y no tiene cita. Al abrir WhatsApp queda como "Le escribiste".
     */
    private static function whatsappAction(): Action
    {
        return SendWhatsAppAction::compose('whatsapp', fn (TreatmentPlan $record) => ($key = self::followUpTemplate($record))
            ? app(FollowUps::class)->draft($key, $record->patient, $record)
            : null)
            ->label('WhatsApp')
            ->visible(fn (TreatmentPlan $record) => $record->patient->account !== null && self::followUpTemplate($record) !== null);
    }

    /** El paciente dijo que no: el presupuesto se cierra como cancelado y deja de aparecer para seguimiento. */
    private static function declineAction(): Action
    {
        return Action::make('decline')
            ->label('No lo aceptó')
            ->icon(Heroicon::OutlinedHandThumbDown)
            ->color('danger')
            ->authorize('decline')
            ->visible(fn (TreatmentPlan $record) => $record->status === TreatmentPlanStatus::Proposed)
            ->modalHeading('El paciente no aceptó el presupuesto')
            ->modalDescription('Queda como cancelado en su historia, con el motivo, y sale de la lista de seguimiento.')
            ->modalSubmitActionLabel('Cerrar presupuesto')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->placeholder('Ej.: el precio, se atiende en otro lado, lo hará más adelante.')
                    ->rows(2)
                    ->maxLength(200),
            ])
            ->action(function (array $data, TreatmentPlan $record) {
                app(TreatmentPlanService::class)->cancel($record, 'el paciente no lo aceptó'.(filled($data['reason'] ?? null) ? ' ('.$data['reason'].')' : ''));

                Notification::make()->success()->title('Presupuesto cerrado')->send();
            });
    }

    private static function followUpTemplate(TreatmentPlan $plan): ?MessageTemplateKey
    {
        return match (true) {
            $plan->status === TreatmentPlanStatus::Proposed && $plan->included_in_plan_id === null => MessageTemplateKey::PlanFollowUp,
            self::waitsForAppointment($plan) && in_array($plan->status, TreatmentPlan::ACCEPTED_STATUSES, true) => MessageTemplateKey::PlanNextSession,
            default => null,
        };
    }

    /** Vigente, con procedimientos por hacer y sin ninguna cita de hoy en adelante. */
    private static function waitsForAppointment(TreatmentPlan $plan): bool
    {
        return in_array($plan->status, [TreatmentPlanStatus::Proposed, ...TreatmentPlan::ACCEPTED_STATUSES], true)
            && $plan->next_appointment_at === null
            && self::openItems($plan)->isNotEmpty();
    }

    /** @return Collection<int, TreatmentPlanItem> */
    private static function openItems(TreatmentPlan $plan): Collection
    {
        return $plan->items->filter(fn (TreatmentPlanItem $item) => $item->isOpen())->values();
    }

    /** "Corona, Endodoncia y 2 más". */
    private static function summary(TreatmentPlan $plan, bool $withTooth): string
    {
        $labels = $plan->items
            ->reject(fn (TreatmentPlanItem $item) => $item->status === PlanItemStatus::Cancelled)
            ->map(fn (TreatmentPlanItem $item) => $item->label($withTooth))
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return 'Sin procedimientos';
        }

        return $labels->take(2)->implode(', ').($labels->count() > 2 ? ' y '.($labels->count() - 2).' más' : '');
    }

    /** Hace cuánto está así: lo que lleva sin respuesta, desde cuándo se trabaja o cuándo terminó. */
    private static function since(TreatmentPlan $plan): ?string
    {
        return match ($plan->status) {
            TreatmentPlanStatus::Draft => 'Sin presentar',
            TreatmentPlanStatus::Proposed => $plan->proposed_at ? self::waiting($plan->proposed_at) : null,
            TreatmentPlanStatus::Accepted, TreatmentPlanStatus::InProgress => $plan->accepted_at ? 'Desde el '.$plan->accepted_at->format('d/m/Y') : null,
            TreatmentPlanStatus::Completed => $plan->completed_at ? 'El '.$plan->completed_at->format('d/m/Y') : null,
            TreatmentPlanStatus::Cancelled => 'El '.$plan->updated_at->format('d/m/Y'),
        };
    }

    /** "Presentado hoy", "Hace 1 día sin respuesta", "Hace 9 días sin respuesta". */
    private static function waiting(CarbonInterface $proposedAt): string
    {
        $days = (int) CarbonImmutable::instance($proposedAt)->startOfDay()->diffInDays(today());

        return match ($days) {
            0 => 'Presentado hoy',
            1 => 'Hace 1 día sin respuesta',
            default => 'Hace '.$days.' días sin respuesta',
        };
    }
}
