<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\ClinicalAlerts;
use App\Filament\Support\PatientTimeline;
use App\Models\Charge;
use App\Models\Patient;
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

class PatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                // La alerta médica arriba de todo, para quien atiende al paciente.
                Group::make(fn (Patient $record) => auth()->user()->can('viewClinical', $record) ? array_filter([ClinicalAlerts::callout($record)]) : [])
                    ->columnSpanFull(),
                Section::make('Datos del paciente')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('full_name')
                            ->label('Nombre'),
                        TextEntry::make('document_number')
                            ->label('Documento')
                            ->formatStateUsing(fn (Patient $record) => $record->document_type?->getLabel().' '.$record->document_number)
                            ->hidden(fn ($state) => blank($state)),
                        TextEntry::make('birth_date')
                            ->label('Fecha de nacimiento')
                            ->date('d/m/Y')
                            ->helperText(fn (Patient $record) => $record->age !== null ? $record->age.' años' : null)
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
                            ->placeholder('Aún no viene'),
                        TextEntry::make('last_visit_at')
                            ->label('Última visita')
                            ->date('d/m/Y')
                            ->hidden(fn ($state) => blank($state)),
                        TextEntry::make('admin_notes')
                            ->label('Notas internas')
                            ->hidden(fn ($state) => blank($state))
                            ->columnSpanFull(),
                    ]),
                Group::make([self::phoneAndFamily(), self::account()])
                    ->columnSpan(1),
                Section::make('Línea de tiempo')
                    ->description('Citas, atenciones, pagos y mensajes, de lo más nuevo a lo más antiguo.')
                    ->icon(Heroicon::OutlinedClock)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        View::make('filament.patients.timeline')
                            ->viewData(fn (Patient $record) => ['events' => PatientTimeline::for($record, auth()->user())]),
                    ]),
            ]);
    }

    private static function phoneAndFamily(): Section
    {
        return Section::make('Celular y familia')
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
                    ->label('Otros pacientes con este celular')
                    ->state(fn (Patient $record) => $record->account
                        ?->patients()
                        ->whereKeyNot($record->getKey())
                        ->orderBy('first_name')
                        ->get()
                        ->map(fn (Patient $p) => $p->full_name.($p->relationship ? ' ('.$p->relationship->getLabel().')' : ''))
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('Ninguno'),
            ]);
    }

    /** Saldo y último pago, para recepción y administración. */
    private static function account(): Section
    {
        return Section::make('Cuenta')
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
