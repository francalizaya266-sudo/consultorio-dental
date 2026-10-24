<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\Charge;
use App\Models\Payment;
use App\Services\Billing\BillingService;
use App\Support\Money;
use App\Support\Phone;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

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
                            ->hidden(fn ($state) => blank($state)),
                        TextEntry::make('promo_code')
                            ->label('Código de campaña')
                            ->state(fn (Appointment $record) => $record->promo_code ?? $record->campaign?->promo_code)
                            ->hidden(fn ($state) => blank($state)),
                        TextEntry::make('patient_note')
                            ->label('Nota del paciente')
                            ->hidden(fn ($state) => blank($state))
                            ->columnSpanFull(),
                        TextEntry::make('internal_notes')
                            ->label('Notas internas')
                            ->hidden(fn ($state) => blank($state))
                            ->columnSpanFull(),
                        TextEntry::make('cancellation_reason')
                            ->label('Motivo de cancelación')
                            ->placeholder('Sin motivo')
                            ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::Cancelled)
                            ->columnSpanFull(),
                    ]),
                self::toCharge(),
                self::billing(),
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

    /**
     * Antes de atenderla: lo que se espera cobrar ese día, para que recepción lo tenga listo
     * (la cuota que vence, la sesión del plan o el tratamiento con su promoción, y el saldo).
     */
    private static function toCharge(): Section
    {
        return Section::make('Para cobrar ese día')
            ->columnSpan(2)
            ->visible(fn (Appointment $record) => auth()->user()->can('viewAny', Charge::class)
                && in_array($record->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true))
            ->schema([
                TextEntry::make('estimate')
                    ->label('Detalle')
                    ->hiddenLabel()
                    ->state(fn (Appointment $record) => collect(app(BillingService::class)->estimateFor($record)['lines'])
                        ->map(fn (array $line) => $line['label'].($line['amount'] === null ? '' : ': '.Money::format($line['amount'])))
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Sin precio en el catálogo para este tratamiento.'),
                TextEntry::make('estimate_total')
                    ->label('Cobrar')
                    ->state(fn (Appointment $record) => Money::format(app(BillingService::class)->estimateFor($record)['total']))
                    ->weight('bold')
                    ->size(TextSize::Large),
            ]);
    }

    /** Lo cargado y lo pagado por la cita, para recepción y administración. */
    private static function billing(): Section
    {
        return Section::make('Cobro')
            ->columnSpan(2)
            ->columns(3)
            ->visible(fn (Appointment $record) => auth()->user()->can('viewAny', Charge::class)
                && ($record->charges()->exists() || $record->payments()->exists()))
            ->schema([
                TextEntry::make('charges')
                    ->label('Cargado')
                    ->state(fn (Appointment $record) => $record->charges()->valid()->orderBy('id')->get()
                        ->map(fn (Charge $c) => $c->description.' · '.Money::format($c->net()).((float) $c->discount > 0 ? ' (con '.Money::format($c->discount).' de descuento)' : ''))
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('Nada'),
                TextEntry::make('payments')
                    ->label('Pagado')
                    ->state(fn (Appointment $record) => $record->payments()->whereNull('voided_at')->orderBy('paid_at')->get()
                        ->map(fn (Payment $p) => Money::format($p->amount).' · '.$p->method->getLabel().' · '.$p->paid_at->format('d/m H:i'))
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('Aún no paga'),
                TextEntry::make('balance')
                    ->label('Saldo del paciente')
                    ->state(fn (Appointment $record) => Money::balance($record->patient->balance()))
                    ->color(fn (Appointment $record) => $record->patient->balance() > 0 ? 'danger' : 'success')
                    ->weight('bold'),
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
