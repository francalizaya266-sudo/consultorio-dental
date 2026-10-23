<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Support\Phone;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AppointmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Cita')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('patient.first_name')
                            ->label('Paciente')
                            ->state(fn (Appointment $record) => $record->patient->full_name)
                            ->url(fn (Appointment $record) => PatientResource::getUrl('view', ['record' => $record->patient]))
                            ->color('primary')
                            ->weight('bold'),
                        TextEntry::make('phone')
                            ->label('Celular')
                            ->state(fn (Appointment $record) => $record->patient->account ? Phone::display($record->patient->account->phone) : null)
                            ->placeholder('Sin celular'),
                        TextEntry::make('starts_at')
                            ->label('Fecha y hora')
                            ->dateTime('l d/m/Y · H:i'),
                        TextEntry::make('duration')
                            ->label('Duración')
                            ->state(fn (Appointment $record) => $record->ends_at->diffInMinutes($record->starts_at, true).' min (+'.$record->buffer_minutes.' de esterilización)'),
                        TextEntry::make('treatment.name')
                            ->label('Tratamiento'),
                        TextEntry::make('doctor.name')
                            ->label('Doctor'),
                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->helperText(fn (Appointment $record) => $record->needsCall() ? 'No confirmó: conviene llamar.' : null),
                        TextEntry::make('booked_via')
                            ->label('Reservada por')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('origin')
                            ->label('¿Cómo nos encontró?')
                            ->placeholder('—'),
                        TextEntry::make('promo_code')
                            ->label('Código de campaña')
                            ->state(fn (Appointment $record) => $record->promo_code ?? $record->campaign?->promo_code)
                            ->placeholder('—'),
                        TextEntry::make('patient_note')
                            ->label('Nota del paciente')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('internal_notes')
                            ->label('Notas internas')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('cancellation_reason')
                            ->label('Motivo de cancelación')
                            ->placeholder('Sin motivo')
                            ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::Cancelled)
                            ->columnSpanFull(),
                    ]),
                Section::make('Historial')
                    ->columnSpan(1)
                    ->schema([
                        RepeatableEntry::make('events')
                            ->hiddenLabel()
                            ->contained(false)
                            ->state(fn (Appointment $record) => $record->events()->with('user')->orderBy('id')->get())
                            ->schema([
                                TextEntry::make('type')
                                    ->hiddenLabel()
                                    ->formatStateUsing(fn (AppointmentEvent $record) => self::eventLabel($record))
                                    ->weight('medium')
                                    ->helperText(fn (AppointmentEvent $record) => collect([
                                        $record->created_at->translatedFormat('d/m H:i'),
                                        $record->actor === 'patient' ? 'Paciente' : ($record->user?->name ?? 'Sistema'),
                                        $record->notes,
                                    ])->filter()->implode(' · ')),
                            ]),
                    ]),
            ]);
    }

    private static function eventLabel(AppointmentEvent $event): string
    {
        $label = match ($event->type) {
            'created' => 'Cita creada',
            'confirmed' => 'Confirmada',
            'rescheduled' => 'Horario cambiado',
            'checked_in' => 'Llegó al consultorio',
            'in_progress' => 'Pasó a atención',
            'completed' => 'Atendida',
            'no_show' => 'No asistió',
            'cancelled' => 'Cancelada',
            'reschedule_requested' => 'Pidió cambiar el horario',
            'reschedule_rejected' => 'Cambio no aprobado',
            default => ucfirst(str_replace('_', ' ', $event->type)),
        };

        if ($event->from_starts_at && $event->to_starts_at) {
            $label .= ': '.$event->from_starts_at->translatedFormat('d/m H:i').' → '.$event->to_starts_at->translatedFormat('d/m H:i');
        }

        return $label;
    }
}
