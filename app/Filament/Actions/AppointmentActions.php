<?php

namespace App\Filament\Actions;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\Notify;
use App\Filament\Support\SlotPicker;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\User;
use App\Services\Booking\AppointmentService;
use App\Services\Clinical\ConsultationService;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Dates;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Acciones rápidas sobre una cita, iguales en la agenda, en "Hoy" y en la ficha de la cita. */
final class AppointmentActions
{
    /** @return list<Action|ActionGroup> */
    public static function all(): array
    {
        return [
            self::confirm(),
            self::missed(),
            self::arrived(),
            self::start(),
            self::continueConsultation(),
            self::complete(),
            CheckoutAction::make(),
            ActionGroup::make([
                self::patientProfile(),
                self::viewConsultation(),
                self::whatsapp(),
                self::move(),
                self::noShow(),
                self::cancel(),
            ]),
        ];
    }

    /**
     * Las del panel al costado de la agenda: los botones del día y WhatsApp a la vista.
     *
     * @return list<Action|ActionGroup>
     */
    public static function panel(): array
    {
        return [
            self::confirm(),
            self::missed(),
            self::arrived(),
            self::start(),
            self::continueConsultation(),
            self::complete(),
            CheckoutAction::make(),
            // A la vista pero en gris: el botón de color es el paso del día (llegó, atender, cobrar).
            self::whatsapp()->color('gray'),
            ActionGroup::make([
                self::patientProfile(),
                self::viewConsultation(),
                self::move(),
                self::noShow(),
                self::cancel(),
            ])->label('Más')->button()->color('gray')->icon(Heroicon::OutlinedEllipsisHorizontal),
        ];
    }

    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label('Confirmar')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            // Confirmar sirve antes de la cita; cuando ya pasó su hora, el paso es "Llegó" o "No asistió".
            ->visible(fn (Appointment $record) => $record->status === AppointmentStatus::Pending && ! $record->ends_at->isPast())
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

    /**
     * El doctor de la cita abre la atención clínica (con su plantilla y odontograma); recepción solo cambia el estado.
     * Si la cita era de un día anterior y nadie la cerró, el doctor la registra igual, con la fecha de la cita.
     */
    public static function start(): Action
    {
        return Action::make('start')
            ->label(fn (Appointment $record) => $record->isFromAnEarlierDay() ? 'Registrar atención' : 'Atender')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->visible(fn (Appointment $record) => self::attendsClinically($record)
                ? (($record->starts_at->isToday() || $record->isLeftOpen()) && in_array($record->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true))
                    || ($record->status === AppointmentStatus::InProgress && $record->loadMissing('consultation')->consultation === null)
                : $record->status === AppointmentStatus::CheckedIn && ! $record->isFromAnEarlierDay())
            ->authorize('update')
            ->action(function (Appointment $record, Action $action) {
                if (! self::attendsClinically($record)) {
                    Notify::run(fn () => app(AppointmentService::class)->markStarted($record, auth()->user()), 'Atención iniciada');

                    return;
                }

                $consultations = app(ConsultationService::class);
                $consultation = null;
                Notify::run(function () use ($record, $consultations, &$consultation) {
                    $consultation = $consultations->startFromAppointment($record, auth()->user());
                }, 'Atención iniciada');

                if ($consultation) {
                    Notify::heldMedications($consultations->heldMedications());
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

    /** De la cita a la ficha del paciente. No aparece donde la ficha ya está a la vista. */
    public static function patientProfile(): Action
    {
        return Action::make('patientProfile')
            ->label('Ficha del paciente')
            ->icon(Heroicon::OutlinedUser)
            ->hidden(fn ($livewire) => $livewire instanceof ViewAppointment || $livewire instanceof RelationManager)
            ->visible(fn (Appointment $record) => PatientResource::canView($record->patient))
            ->url(fn (Appointment $record) => PatientResource::getUrl('view', ['record' => $record->patient]));
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

    /**
     * Recepción cierra la cita sin historia escrita. También la que ya terminó su hora y quedó sin cerrar (de hoy
     * o de un día anterior), si el paciente sí vino: como nadie anotó que llegó, se pregunta antes.
     */
    public static function complete(): Action
    {
        return Action::make('complete')
            ->label('Atendido')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('gray')
            ->visible(fn (Appointment $record) => (in_array($record->status, [AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true) || $record->endedWithoutArriving() || $record->isLeftOpen())
                && ! self::attendsClinically($record))
            ->requiresConfirmation(fn (Appointment $record) => $record->isFromAnEarlierDay() || $record->endedWithoutArriving())
            ->modalHeading(fn (Appointment $record) => '¿El paciente vino el '.$record->starts_at->translatedFormat('l j \d\e F').'?')
            ->modalDescription(fn (Appointment $record) => 'La cita queda atendida con su fecha y se registra lo programado: '.($record->planItem?->label(withTooth: false) ?? $record->treatment->name).'. Si no vino, usa "No asistió".')
            ->modalSubmitActionLabel('Sí, se atendió')
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->markCompleted($record, auth()->user()),
                'Cita atendida',
            ));
    }

    /** En "Más", mientras la cita está en curso: el paciente todavía puede llegar tarde. */
    public static function noShow(): Action
    {
        return self::noShowAction('noShow')
            ->visible(fn (Appointment $record) => $record->status->isChangeable() && $record->starts_at->isPast() && ! $record->ends_at->isPast());
    }

    /** Ya terminó su hora y nunca llegó: marcar la inasistencia pasa a ser el paso del día. */
    public static function missed(): Action
    {
        return self::noShowAction('missed')
            ->outlined()
            ->visible(fn (Appointment $record) => $record->status->isChangeable() && $record->ends_at->isPast());
    }

    private static function noShowAction(string $name): Action
    {
        return Action::make($name)
            ->label('No asistió')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('¿Marcar que el paciente no asistió?')
            ->modalSubmitActionLabel('Marcar inasistencia')
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->markNoShow($record, auth()->user()),
                'Marcada como inasistencia',
            ));
    }

    /** Para la pestaña "Sin cerrar": marca de una vez a los que no vinieron. Las demás citas no se tocan. */
    public static function noShowBulk(): BulkAction
    {
        return BulkAction::make('noShowBulk')
            ->label('No asistieron')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('¿Marcar que estos pacientes no asistieron?')
            ->modalSubmitActionLabel('Marcar inasistencias')
            ->modalDescription('Solo cambia las citas pendientes o confirmadas que ya pasaron. Las que estaban en sala o en atención se quedan como están.')
            ->visible(fn (mixed $livewire) => property_exists($livewire, 'activeTab') && $livewire->activeTab === 'unclosed')
            ->authorizeIndividualRecords('update')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
                $marked = 0;
                foreach ($records as $record) {
                    /** @var Appointment $record */
                    if ($record->status->isChangeable() && $record->starts_at->isPast()) {
                        app(AppointmentService::class)->markNoShow($record, auth()->user());
                        $marked++;
                    }
                }

                Notification::make()
                    ->title($marked === 1 ? '1 cita marcada como inasistencia' : $marked.' citas marcadas como inasistencia')
                    ->success()
                    ->send();
            });
    }

    public static function move(): Action
    {
        return Action::make('move')
            ->label('Mover cita')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->modalHeading('Mover cita')
            ->modalDescription(fn (Appointment $record) => 'Ahora: '.Dates::dayAndTime($record->starts_at).'. Elige el nuevo horario.')
            ->modalSubmitActionLabel('Mover cita')
            ->visible(fn (Appointment $record) => $record->status->isChangeable())
            ->authorize('update')
            ->fillForm(fn (Appointment $record) => [
                'doctor_id' => $record->doctor_id,
                'date' => $record->starts_at->toDateString(),
            ])
            ->schema(fn (Appointment $record) => [
                ...SlotPicker::fields($record),
                Checkbox::make('agreed')
                    ->label('Ya lo acordé con el paciente')
                    ->helperText('Queda confirmada y no se le pide confirmar. Si no, se prepara el aviso para que confirme.'),
            ])
            ->action(fn (array $data, Appointment $record, Action $action) => Notify::run(
                fn () => app(AppointmentService::class)->rescheduleByStaff(
                    $record,
                    SlotPicker::startsAt($data),
                    auth()->user(),
                    Doctor::findOrFail($data['doctor_id']),
                    (bool) ($data['agreed'] ?? false),
                ),
                ($data['agreed'] ?? false) ? 'Cita movida y confirmada.' : 'Cita movida. El aviso al paciente está listo en Avisos.',
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
            ->modalSubmitActionLabel('Cancelar la cita')
            ->modalCancelActionLabel('Volver')
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
