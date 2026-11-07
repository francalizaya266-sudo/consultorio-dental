<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\PatientRelationship;
use App\Filament\Resources\Accounts\AccountActions;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\ClinicalAlerts;
use App\Filament\Support\PatientSummary;
use App\Filament\Support\PatientTimeline;
use App\Models\Charge;
use App\Models\Patient;
use App\Services\Clinical\ConsentService;
use App\Support\Money;
use App\Support\Phone;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
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
                // A la izquierda, los datos y la línea de tiempo; a la derecha, el celular y la cuenta.
                Group::make([
                    // Etiqueta y dato en la misma línea: la ficha entra en media pantalla.
                    Section::make('Datos del paciente')
                        ->columns(['default' => 1, 'md' => 2])
                        ->compact()
                        ->inlineLabel()
                        ->schema([
                            TextEntry::make('document_number')
                                ->label('Documento')
                                ->formatStateUsing(fn (Patient $record) => $record->document_type?->getLabel().' '.$record->document_number)
                                ->hidden(fn ($state) => blank($state)),
                            TextEntry::make('birth_date')
                                ->label('Nacimiento')
                                ->formatStateUsing(fn (Patient $record) => $record->birth_date->format('d/m/Y').($record->age !== null ? ' · '.$record->age.' años' : ''))
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
                            TextEntry::make('origin')
                                ->label('¿Cómo nos encontró?')
                                ->hidden(fn ($state) => blank($state)),
                            TextEntry::make('first_visit_at')
                                ->label('Primera visita')
                                ->date('d/m/Y')
                                ->hidden(fn ($state) => blank($state)),
                            TextEntry::make('last_visit_at')
                                ->label('Última visita')
                                ->date('d/m/Y')
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
                                ->viewData(fn (Patient $record) => ['events' => PatientTimeline::for($record, auth()->user())]),
                        ]),
                ])
                    ->columnSpan(['default' => 'full', 'lg' => 2]),
                Group::make([self::phoneAndFamily(), self::account()])
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

    /** Saldo y último pago, para recepción y administración. */
    private static function account(): Section
    {
        return Section::make('Cuenta')
            ->compact()
            ->visible(fn () => auth()->user()->can('viewAny', Charge::class))
            ->schema([
                TextEntry::make('balance')
                    ->label('Saldo')
                    ->state(fn (Patient $record) => Money::balance($record->balance()))
                    ->color(fn (Patient $record) => $record->balance() > 0 ? 'danger' : 'success')
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold),
                TextEntry::make('last_payment')
                    ->label('Último pago')
                    ->state(function (Patient $record) {
                        $payment = $record->payments()->whereNull('voided_at')->latest('paid_at')->first();

                        return $payment ? $payment->paid_at->format('d/m/Y').' · '.Money::format($payment->amount).' · '.$payment->method->getLabel() : null;
                    })
                    ->placeholder('Aún no hay pagos'),
            ]);
    }
}
