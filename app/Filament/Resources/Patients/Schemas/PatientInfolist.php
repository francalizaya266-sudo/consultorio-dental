<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Patient;
use App\Support\Phone;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PatientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Datos del paciente')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('full_name')
                            ->label('Nombre'),
                        TextEntry::make('document_number')
                            ->label('Documento')
                            ->formatStateUsing(fn (Patient $record) => $record->document_type?->getLabel().' '.$record->document_number)
                            ->placeholder('—'),
                        TextEntry::make('birth_date')
                            ->label('Fecha de nacimiento')
                            ->date('d/m/Y')
                            ->helperText(fn (Patient $record) => $record->age !== null ? $record->age.' años' : null)
                            ->placeholder('—'),
                        TextEntry::make('sex')
                            ->label('Sexo')
                            ->placeholder('—'),
                        TextEntry::make('email')
                            ->label('Correo')
                            ->placeholder('—'),
                        TextEntry::make('address')
                            ->label('Dirección')
                            ->state(fn (Patient $record) => collect([$record->address, $record->district])->filter()->implode(', '))
                            ->placeholder('—'),
                        TextEntry::make('emergency_contact_name')
                            ->label('Contacto de emergencia')
                            ->state(fn (Patient $record) => collect([$record->emergency_contact_name, $record->emergency_contact_phone])->filter()->implode(' · '))
                            ->placeholder('—'),
                        TextEntry::make('origin')
                            ->label('¿Cómo nos encontró?')
                            ->placeholder('—'),
                        TextEntry::make('first_visit_at')
                            ->label('Primera visita')
                            ->date('d/m/Y')
                            ->placeholder('Aún no viene'),
                        TextEntry::make('last_visit_at')
                            ->label('Última visita')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('admin_notes')
                            ->label('Notas internas')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Celular y familia')
                    ->columnSpan(1)
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
                            ->placeholder('—'),
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
                    ]),
            ]);
    }
}
