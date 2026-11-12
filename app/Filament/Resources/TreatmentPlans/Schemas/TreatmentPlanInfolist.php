<?php

namespace App\Filament\Resources\TreatmentPlans\Schemas;

use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\TreatmentPlans\Tables\TreatmentPlansTable;
use App\Models\Appointment;
use App\Models\TreatmentPlan;
use App\Services\Billing\BillingService;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Un presupuesto en su propia página: a la izquierda, lo que incluye con su precio y cómo va el pago; a la
 * derecha, en qué quedó con el paciente (estado, último aviso, próxima cita) y de quién es. Quien no ve lo
 * clínico lo lee sin la pieza ni el título que le puso el doctor (Ley 29733).
 */
class TreatmentPlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make([
                    Section::make('Lo que incluye')
                        ->description(fn (TreatmentPlan $record) => self::includesNote($record))
                        ->icon(Heroicon::OutlinedClipboardDocumentList)
                        ->schema([
                            View::make('filament.consultations.plans')
                                ->viewData(fn (TreatmentPlan $record) => [
                                    'plans' => [$record],
                                    'withTooth' => self::seesClinical($record),
                                    'showHead' => false,
                                    // Lo que firmó va abajo, en "Pago", con su cronograma.
                                    'payments' => [],
                                    'paymentOptions' => [$record->getKey() => app(TreatmentPlanService::class)->paymentOptions($record)],
                                    'url' => null,
                                ]),
                        ]),
                    Section::make('Pago')
                        ->description('Lo que firmó, lo que lleva pagado y lo que falta.')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->schema([
                            View::make('filament.plans.payment')
                                ->viewData(fn (TreatmentPlan $record) => [
                                    'progress' => app(BillingService::class)->planProgress($record),
                                    'paymentText' => app(TreatmentPlanService::class)->paymentText($record),
                                    'states' => $record->hasSchedule() ? app(BillingService::class)->installmentStates($record) : [],
                                    'signatures' => $record->signatures,
                                ]),
                        ])
                        ->visible(fn (TreatmentPlan $record) => $record->accepted_at !== null && $record->included_in_plan_id === null),
                ])
                    ->columnSpan(['default' => 'full', 'lg' => 2]),

                Group::make([
                    Section::make('Seguimiento')
                        ->compact()
                        ->schema([
                            TextEntry::make('status')
                                ->label('Estado')
                                ->badge()
                                ->helperText(fn (TreatmentPlan $record) => TreatmentPlansTable::since($record)),
                            TextEntry::make('last_contacted_at')
                                ->label('Último aviso por WhatsApp')
                                ->formatStateUsing(fn ($state) => 'Le escribiste '.CarbonImmutable::parse($state)->diffForHumans())
                                ->placeholder('Todavía no se le escribe')
                                ->visible(fn (TreatmentPlan $record) => self::isFollowedUp($record)),
                            TextEntry::make('next_appointment_at')
                                ->label('Próxima cita')
                                ->formatStateUsing(fn ($state) => ucfirst(CarbonImmutable::parse($state)->translatedFormat('D j M, H:i')))
                                ->url(fn (TreatmentPlan $record) => self::nextAppointmentUrl($record))
                                ->color(fn (TreatmentPlan $record) => self::nextAppointmentUrl($record) ? 'primary' : null)
                                ->placeholder(fn (TreatmentPlan $record) => TreatmentPlansTable::waitsForAppointment($record) ? 'Sin cita: falta agendar lo que sigue' : 'Sin cita')
                                ->visible(fn (TreatmentPlan $record) => $record->isOpen()),
                            TextEntry::make('next_item')
                                ->label('Lo que sigue')
                                ->state(fn (TreatmentPlan $record) => self::nextItem($record))
                                ->visible(fn (TreatmentPlan $record) => $record->isOpen() && filled(self::nextItem($record))),
                        ]),
                    Section::make('Paciente')
                        ->compact()
                        ->schema([
                            TextEntry::make('patient.first_name')
                                ->label('Nombre')
                                ->state(fn (TreatmentPlan $record) => $record->patient->full_name)
                                ->url(fn (TreatmentPlan $record) => PatientResource::canView($record->patient) ? PatientResource::getUrl('view', ['record' => $record->patient]) : null)
                                ->color('primary')
                                ->weight('bold'),
                            TextEntry::make('patient.account.phone')
                                ->label('Celular')
                                ->formatStateUsing(fn (string $state) => Phone::display($state))
                                ->placeholder('Sin celular'),
                            TextEntry::make('doctor.name')
                                ->label('Lo armó')
                                ->placeholder('—'),
                            TextEntry::make('two_for_one')
                                ->label('2x1')
                                ->state(fn (TreatmentPlan $record) => self::twoForOne($record))
                                ->visible(fn (TreatmentPlan $record) => filled(self::twoForOne($record))),
                        ]),
                ])
                    ->columnSpan(['default' => 'full', 'lg' => 1]),
            ]);
    }

    /** Recepción ve el procedimiento, no la pieza. */
    private static function seesClinical(TreatmentPlan $plan): bool
    {
        return auth()->user()->can('view', $plan);
    }

    /** Bajo el título: el nombre que le puso el doctor (solo para quien ve lo clínico) y hasta cuándo vale el precio. */
    private static function includesNote(TreatmentPlan $plan): ?string
    {
        $until = $plan->validUntil();

        return collect([
            self::seesClinical($plan) && filled($plan->title) && $plan->title !== 'Plan de tratamiento' ? $plan->title : null,
            match (true) {
                $until === null => null,
                $until->copy()->endOfDay()->isPast() => 'Su precio venció el '.$until->format('d/m/Y'),
                default => 'Precio válido hasta el '.$until->format('d/m/Y'),
            },
        ])->filter()->implode(' · ') ?: null;
    }

    /** Se le escribe mientras no responde o mientras le falta cita; si ya se le escribió, se sigue viendo cuándo. */
    private static function isFollowedUp(TreatmentPlan $plan): bool
    {
        return $plan->isOpen()
            && ($plan->last_contacted_at !== null
                || $plan->status === TreatmentPlanStatus::Proposed
                || TreatmentPlansTable::waitsForAppointment($plan));
    }

    /** "Endodoncia · sesión 1 de 2": lo primero que queda por hacer. */
    private static function nextItem(TreatmentPlan $plan): ?string
    {
        $item = TreatmentPlansTable::openItems($plan)->first();

        if ($item === null) {
            return null;
        }

        return $item->label(self::seesClinical($plan))
            .($item->sessions_planned > 1 ? ' · sesión '.min($item->sessions_done + 1, $item->sessions_planned).' de '.$item->sessions_planned : '');
    }

    /** La próxima cita del paciente, si quien mira la puede abrir. */
    private static function nextAppointmentUrl(TreatmentPlan $plan): ?string
    {
        if ($plan->next_appointment_at === null) {
            return null;
        }

        /** @var Appointment|null $appointment */
        $appointment = AppointmentResource::getEloquentQuery()
            ->where('patient_id', $plan->patient_id)
            ->where('starts_at', $plan->next_appointment_at)
            ->first();

        return $appointment ? AppointmentResource::getUrl('view', ['record' => $appointment]) : null;
    }

    /** En un 2x1: a quién más se atiende con este plan, o quién paga el de este paciente. */
    private static function twoForOne(TreatmentPlan $plan): ?string
    {
        if ($plan->included_in_plan_id !== null) {
            return 'Sin costo: lo paga '.($plan->includedIn?->patient?->full_name ?? 'la otra persona');
        }

        $companions = $plan->companionNames();

        return $companions ? 'También se atiende a '.$companions : null;
    }
}
