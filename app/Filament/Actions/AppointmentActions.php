<?php

namespace App\Filament\Actions;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Support\Notify;
use App\Filament\Support\SlotPicker;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Clinical\ConsultationService;
use App\Services\WhatsApp\WhatsAppOutbox;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/** Acciones rápidas sobre una cita, iguales en la agenda, en "Hoy" y en la ficha de la cita. */
final class AppointmentActions
{
    /** @return list<Action|ActionGroup> */
    public static function all(): array
    {
        return [
            self::confirm(),
            self::arrived(),
            self::start(),
            self::continueConsultation(),
            self::complete(),
            ActionGroup::make([
                self::viewConsultation(),
                self::whatsapp(),
                self::move(),
                self::noShow(),
                self::cancel(),
            ]),
        ];
    }

    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label('Confirmar')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::Pending)
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->confirmByStaff($record, auth()->user()),
                'Cita confirmada',
            ));
    }

    public static function arrived(): Action
    {
        return Action::make('arrived')
            ->label('Llegó')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('info')
            ->visible(fn (Appointment $record) => $record->status->isChangeable() && $record->starts_at->isToday())
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->markArrived($record, auth()->user()),
                'Paciente en sala',
            ));
    }

    /** El doctor de la cita abre la atención clínica (con su plantilla y odontograma); recepción solo cambia el estado. */
    public static function start(): Action
    {
        return Action::make('start')
            ->label('Atender')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->visible(fn (Appointment $record) => self::attendsClinically($record)
                ? ($record->starts_at->isToday() && in_array($record->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true))
                    || ($record->status === AppointmentStatus::InProgress && $record->loadMissing('consultation')->consultation === null)
                : $record->status === AppointmentStatus::CheckedIn)
            ->authorize('update')
            ->action(function (Appointment $record, Action $action) {
                if (! self::attendsClinically($record)) {
                    Notify::run(fn () => app(AppointmentService::class)->markStarted($record, auth()->user()), 'Atención iniciada');

                    return;
                }

                $consultation = null;
                Notify::run(function () use ($record, &$consultation) {
                    $consultation = app(ConsultationService::class)->startFromAppointment($record, auth()->user());
                }, 'Atención iniciada');

                if ($consultation) {
                    $action->redirect(ConsultationResource::getUrl('edit', ['record' => $consultation]));
                }
            });
    }

    /** Vuelve a la atención que el doctor dejó abierta. */
    public static function continueConsultation(): Action
    {
        return Action::make('continueConsultation')
            ->label('Continuar atención')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::InProgress
                && self::attendsClinically($record)
                && self::openConsultation($record) !== null)
            ->url(fn (Appointment $record) => ConsultationResource::getUrl('edit', ['record' => $record->consultation]));
    }

    public static function viewConsultation(): Action
    {
        return Action::make('viewConsultation')
            ->label('Ver atención')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->visible(fn (Appointment $record) => in_array($record->status, [AppointmentStatus::InProgress, AppointmentStatus::Completed], true)
                && auth()->user()->canSeeClinical()
                && $record->loadMissing('consultation')->consultation !== null
                && auth()->user()->can('view', $record->consultation))
            ->url(fn (Appointment $record) => ConsultationResource::urlFor($record->consultation));
    }

    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('Atendido')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('gray')
            ->visible(fn (Appointment $record) => in_array($record->status, [AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true)
                && ! self::attendsClinically($record))
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->markCompleted($record, auth()->user()),
                'Cita atendida',
            ));
    }

    public static function noShow(): Action
    {
        return Action::make('noShow')
            ->label('No asistió')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('¿Marcar que el paciente no asistió?')
            ->visible(fn (Appointment $record) => $record->status->isChangeable() && $record->starts_at->isPast())
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->markNoShow($record, auth()->user()),
                'Marcada como inasistencia',
            ));
    }

    public static function move(): Action
    {
        return Action::make('move')
            ->label('Cambiar horario')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('warning')
            ->modalHeading('Cambiar horario')
            ->modalDescription('El paciente recibirá el aviso con el nuevo horario para confirmarlo.')
            ->modalSubmitActionLabel('Mover cita')
            ->visible(fn (Appointment $record) => $record->status->isChangeable())
            ->authorize('update')
            ->fillForm(fn (Appointment $record) => [
                'doctor_id' => $record->doctor_id,
                'date' => $record->starts_at->toDateString(),
            ])
            ->schema(fn (Appointment $record) => SlotPicker::fields($record))
            ->action(fn (array $data, Appointment $record, Action $action) => Notify::run(
                fn () => app(AppointmentService::class)->rescheduleByStaff(
                    $record,
                    SlotPicker::startsAt($data),
                    auth()->user(),
                    Doctor::findOrFail($data['doctor_id']),
                ),
                'Cita movida. El aviso al paciente está listo en "Avisos por enviar".',
                $action,
            ));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar cita')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Cancelar cita')
            ->modalSubmitActionLabel('Sí, cancelar')
            ->visible(fn (Appointment $record) => $record->status->isChangeable())
            ->authorize('update')
            ->schema([
                Textarea::make('reason')->label('Motivo')->rows(2)->maxLength(250),
                Toggle::make('notify')->label('Avisar al paciente por WhatsApp')->default(true),
            ])
            ->action(fn (array $data, Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->cancelByStaff($record, auth()->user(), $data['reason'] ?? null, (bool) $data['notify']),
                'Cita cancelada',
            ));
    }

    private static function openConsultation(Appointment $record): ?Consultation
    {
        $consultation = $record->loadMissing('consultation')->consultation;

        return $consultation?->closed_at === null ? $consultation : null;
    }

    /** El usuario es el doctor de la cita y escribe la historia. */
    private static function attendsClinically(Appointment $record): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->canSeeClinical() && $user->doctorId() === $record->doctor_id;
    }

    /** Abre el aviso pendiente de la cita o, si no hay, uno nuevo con sus datos y su enlace. */
    public static function whatsapp(): Action
    {
        return SendWhatsAppAction::make('whatsapp', function (Appointment $record) {
            return $record->messages()->pending()->latest('id')->first()
                ?? app(WhatsAppOutbox::class)->queueForAppointment($record, MessageTemplateKey::AppointmentScheduled);
        })
            ->label('WhatsApp')
            ->visible(fn (Appointment $record) => $record->patient->account_id !== null)
            ->authorize('update');
    }
}
