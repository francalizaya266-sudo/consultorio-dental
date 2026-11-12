<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\PatientRelationship;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Accounts\AccountActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\TreatmentPlans\Tables\TreatmentPlansTable;
use App\Filament\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Filament\Support\ClinicalAlerts;
use App\Filament\Support\PatientSummary;
use App\Filament\Support\PatientTimeline;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Services\Clinical\ConsentService;
use App\Support\Money;
use App\Support\Phone;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class PatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                // Bajo el nombre: el siguiente paso con su botón y, en etiquetas, saldo, próxima cita, control, faltas, cumpleaños y origen.
                View::make('filament.patients.summary')
                    ->viewData(fn (Patient $record, $livewire) => PatientSummary::viewData($record, auth()->user(), $livewire))
                    ->columnSpanFull(),
                // La alerta médica arriba de todo, para quien atiende al paciente.
                Group::make(fn (Patient $record) => auth()->user()->can('viewClinical', $record) ? array_filter([ClinicalAlerts::callout($record)]) : [])
                    ->columnSpanFull(),
                // A la izquierda, los datos y la línea de tiempo; a la derecha, el celular y la familia y, debajo, sus
                // presupuestos. El saldo va una sola vez, en las etiquetas de arriba; lo que pagó, en la línea de tiempo.
                Group::make([
                    // Etiqueta y dato en la misma línea: la ficha entra en media pantalla.
                    Section::make('Datos del paciente')
                        ->columns(['default' => 1, 'md' => 2])
                        ->compact()
                        ->inlineLabel()
                        ->schema([
                            // Documento, edad y desde cuándo viene ya están bajo el nombre (PatientSummary::subheading);
                            // cómo nos encontró y la última visita, en las etiquetas y en la línea de tiempo.
                            TextEntry::make('birth_date')
                                ->label('Nacimiento')
                                ->date('d/m/Y')
                                ->hidden(fn ($state) => blank($state)),
                            TextEntry::make('sex')
                                ->label('Sexo')
                                ->hidden(fn ($state) => blank($state)),
                            TextEntry::make('email')
                                ->label('Correo')
                                ->hidden(fn ($state) => blank($state)),
                            TextEntry::make('address')
                                ->label('Dirección')
                                ->state(fn (Patient $record) => collect([$record->address, $record->district])->filter()->implode(', '))
                                ->hidden(fn ($state) => blank($state)),
                            TextEntry::make('emergency_contact_name')
                                ->label('Contacto de emergencia')
                                ->state(fn (Patient $record) => collect([$record->emergency_contact_name, $record->emergency_contact_phone])->filter()->implode(' · '))
                                ->hidden(fn ($state) => blank($state)),
                            TextEntry::make('admin_notes')
                                ->label('Notas internas')
                                ->hidden(fn ($state) => blank($state))
                                ->columnSpanFull(),
                        ]),
                    Section::make('Línea de tiempo')
                        ->description('Citas, atenciones, pagos y mensajes, de lo más nuevo a lo más antiguo.')
                        ->icon(Heroicon::OutlinedClock)
                        ->collapsible()
                        ->schema([
                            View::make('filament.patients.timeline')
                                ->viewData(fn (Patient $record, $livewire) => [
                                    'events' => PatientTimeline::for($record, auth()->user()),
                                    // "Anular" abre su ventana en la ficha, que es la que la tiene.
                                    'canVoid' => $livewire instanceof ViewPatient,
                                ]),
                        ]),
                ])
                    ->columnSpan(['default' => 'full', 'lg' => 2]),
                Group::make([self::phoneAndFamily(), self::plans()])
                    ->columnSpan(['default' => 'full', 'lg' => 1]),
            ]);
    }

    /**
     * El celular de la familia y quiénes lo comparten (cada uno lleva a su ficha), el parentesco y, si es menor,
     * su apoderado. Desde aquí se le envía el enlace de Mi cuenta: ya no hace falta otra pantalla.
     */
    private static function phoneAndFamily(): Section
    {
        return Section::make('Celular y familia')
            ->key('family')
            ->compact()
            ->headerActions([
                AccountActions::sendAccessLink(fn (Patient $record) => $record->account)
                    ->link()
                    ->visible(fn (Patient $record) => $record->account !== null && ! $record->account->isBlocked() && auth()->user()->can('view', $record->account)),
            ])
            ->schema([
                TextEntry::make('account.phone')
                    ->label('Celular')
                    ->formatStateUsing(fn (string $state) => Phone::display($state))
                    ->url(fn (Patient $record) => $record->account && auth()->user()->can('view', $record->account) ? AccountResource::getUrl('view', ['record' => $record->account]) : null)
                    ->helperText(fn (Patient $record) => match (true) {
                        $record->account === null => null,
                        $record->account->isBlocked() => 'Bloqueado para reservas web',
                        $record->account->isVerified() => 'Verificado por WhatsApp',
                        default => 'Aún no verificado',
                    })
                    ->placeholder('Sin celular'),
                TextEntry::make('relationship')
                    ->label('Parentesco con el titular')
                    ->hidden(fn ($state) => blank($state)),
                TextEntry::make('family')
                    ->label('Familia con este celular')
                    ->state(fn (Patient $record) => $record->account
                        ?->patients()
                        ->whereKeyNot($record->getKey())
                        ->orderBy('first_name')
                        ->get()
                        ->map(fn (Patient $relative) => new HtmlString('<a href="'.e(PatientResource::getUrl('view', ['record' => $relative])).'">'.e($relative->full_name).'</a>'
                            .($relative->relationship ? ' · '.e($relative->relationship->getLabel()) : '')))
                        ->all())
                    ->listWithLineBreaks()
                    ->extraAttributes(['class' => 'pt-family'])
                    ->placeholder('Nadie más'),
                // Quién firma por él: su apoderado o, si no se registró, el titular del celular.
                TextEntry::make('guardian_name')
                    ->label('Apoderado')
                    ->state(function (Patient $record) {
                        $signer = app(ConsentService::class)->defaultSigner($record);

                        return $signer['signer_relationship'] === PatientRelationship::Self
                            ? null
                            : collect([$signer['signer_name'], $signer['signer_relationship']->getLabel(), $signer['signer_document']])->filter()->implode(' · ');
                    })
                    ->placeholder('Falta: quién firma por él')
                    ->visible(fn (Patient $record) => $record->isMinor()),
            ]);
    }

    /**
     * Lo que se le propuso: cada presupuesto o plan con lo que incluye, en qué quedó y cuánto suma. Cada uno abre
     * su página, donde se le escribe, se le agenda y se le cobra. Sin esto, quien no ve la historia clínica no
     * tenía cómo saber desde la ficha qué se le presupuestó.
     */
    private static function plans(): Section
    {
        return Section::make('Presupuestos y planes')
            ->key('plans')
            ->compact()
            ->schema([
                View::make('filament.patients.plans')
                    ->viewData(fn (Patient $record) => ['rows' => self::planRows($record)]),
            ])
            ->visible(fn (Patient $record) => self::planRows($record) !== []);
    }

    /**
     * Los presupuestos del paciente que quien mira puede abrir, los vigentes primero. Quien no ve lo clínico
     * lee el procedimiento, no la pieza (Ley 29733).
     *
     * @return list<array{url: string, summary: string, total: string, status: TreatmentPlanStatus, since: ?string, payment: ?HtmlString, open: bool}>
     */
    private static function planRows(Patient $patient): array
    {
        if (! TreatmentPlanResource::canViewAny()) {
            return [];
        }

        $withTooth = auth()->user()->can('viewClinical', $patient);

        return once(fn () => TreatmentPlanResource::forPatient($patient)
            ->map(fn (TreatmentPlan $plan) => [
                'url' => TreatmentPlanResource::getUrl('view', ['record' => $plan]),
                'summary' => TreatmentPlansTable::summary($plan, $withTooth),
                'total' => Money::format($plan->total()),
                'status' => $plan->status,
                'since' => TreatmentPlansTable::since($plan),
                'payment' => TreatmentPlansTable::paymentSummary($plan),
                'open' => $plan->isOpen(),
            ])
            ->all());
    }
}
