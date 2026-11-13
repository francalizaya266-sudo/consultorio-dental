<?php

namespace App\Filament\Actions;

use App\Enums\AppointmentStatus;
use App\Enums\CancelledBy;
use App\Enums\MessageTemplateKey;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryResource;
use App\Filament\Support\ArrivalChecklist;
use App\Filament\Support\Notify;
use App\Filament\Support\SlotPicker;
use App\Filament\Support\VisitFlags;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\WaitlistService;
use App\Services\Clinical\ConsultationService;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Dates;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Los botones de una cita. Son los mismos y van en el mismo orden en todos los lugares donde aparece una cita: primero
 * el paso del día (confirmar, llegó, atender, cobrar), después WhatsApp, la ficha del paciente y lo demás. Lo único
 * que cambia de un lugar a otro es cuánto cabe a la vista.
 */
final class AppointmentActions
{
    /**
     * Las de una fila de tabla (Citas, Hoy, las citas de la ficha): el paso del día a la vista y lo demás en el menú,
     * porque en una fila no hay más sitio.
     *
     * @return list<Action|ActionGroup>
     */
    public static function all(): array
    {
        return [
            ...self::daySteps(),
            ActionGroup::make([
                self::whatsapp(),
                self::patientProfile(),
                ...self::others(),
            ])->dropdownWidth(Width::ExtraSmall),
        ];
    }

    /**
     * Las del panel al costado de la agenda: WhatsApp a la vista y lo demás en "Más". La ficha del paciente es el
     * enlace que el panel ya trae bajo los datos: no se repite en el menú.
     *
     * @return list<Action|ActionGroup>
     */
    public static function panel(): array
    {
        return [
            ...self::daySteps(),
            // A la vista pero en gris: el botón de color es el paso del día (llegó, atender, cobrar).
            self::whatsapp()->color('gray'),
            self::more(),
        ];
    }

    /**
     * Las de la página de la cita: como en el panel, con la ficha del paciente como botón (una sola vez).
     *
     * @return list<Action|ActionGroup>
     */
    public static function page(): array
    {
        return [
            ...self::daySteps(),
            self::whatsapp()->color('gray'),
            self::patientProfile()->color('gray'),
            self::more(),
        ];
    }

    /**
     * El paso que toca según el estado de la cita, y deshacer el último.
     *
     * @return list<Action>
     */
    private static function daySteps(): array
    {
        return [
            self::confirm(),
            self::missed(),
            self::arrived(),
            self::start(),
            self::continueConsultation(),
            self::complete(),
            CheckoutAction::make(),
            self::undo(),
        ];
    }

    /**
     * Lo que no es el paso del día, en su orden de siempre.
     *
     * @return list<Action>
     */
    private static function others(): array
    {
        return [
            self::viewConsultation(),
            self::move(),
            self::changeTreatment(),
            self::notes(),
            self::changeRequest(),
            self::closeWithoutDoctor(),
            self::noShow(),
            self::undoInMenu(),
            self::cancel(),
        ];
    }

    private static function more(): ActionGroup
    {
        return ActionGroup::make(self::others())->label('Más')->button()->color('gray')->icon(Heroicon::OutlinedEllipsisHorizontal)->dropdownWidth(Width::ExtraSmall);
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
            // Al llegar se dice lo que conviene resolver mientras espera: datos que faltan, deuda, laboratorio.
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->markArrived($record, auth()->user()),
                'Paciente en sala',
                decorate: fn (Notification $notification) => ArrivalChecklist::addTo($notification, $record, auth()->user()),
            ));
    }

    /**
     * El doctor de la cita abre la atención clínica (con su plantilla y odontograma); recepción solo cambia el estado.
     * Si la cita era de un día anterior y nadie la cerró, el doctor la registra igual, con la fecha de la cita.
     */
    public static function start(): Action
    {
        return Action::make('start')
            // Recepción no atiende: solo anota que el paciente ya pasó con el doctor.
            ->label(fn (Appointment $record) => match (true) {
                ! self::attendsClinically($record) => 'Pasó a consulta',
                $record->isFromAnEarlierDay() => 'Registrar atención',
                default => 'Atender',
            })
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->visible(fn (Appointment $record) => self::attendsClinically($record)
                ? (($record->starts_at->isToday() || $record->isLeftOpen()) && in_array($record->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true))
                    || ($record->status === AppointmentStatus::InProgress && $record->loadMissing('consultation')->consultation === null)
                : $record->status === AppointmentStatus::CheckedIn && ! $record->isFromAnEarlierDay())
            ->authorize('update')
            ->action(function (Appointment $record, Action $action) {
                if (! self::attendsClinically($record)) {
                    Notify::run(fn () => app(AppointmentService::class)->markStarted($record, auth()->user()), 'Paciente en consulta');

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

    /** De la cita a la ficha del paciente. No aparece en las citas de la ficha misma. */
    public static function patientProfile(): Action
    {
        return Action::make('patientProfile')
            ->label('Ficha del paciente')
            ->icon(Heroicon::OutlinedUser)
            ->hidden(fn ($livewire) => $livewire instanceof RelationManager)
            ->visible(fn (Appointment $record) => PatientResource::canView($record->patient))
            ->url(fn (Appointment $record) => PatientResource::getUrl('view', ['record' => $record->patient]));
    }

    /**
     * La cita que dice "Pidió cambio" lleva a lo que pidió el paciente, en "Cambios pedidos": ahí se aprueba uno de
     * sus horarios o se le da otro.
     */
    public static function changeRequest(): Action
    {
        return Action::make('changeRequest')
            ->label('Ver el cambio que pidió')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (Appointment $record, $livewire) => RescheduleRequestResource::canViewAny()
                && VisitFlags::of($record, auth()->user(), $livewire instanceof HasTable ? $livewire->getTableRecords() : [])['reschedule'])
            ->url(fn (Appointment $record) => RescheduleRequestResource::forPatientUrl($record->patient));
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
     * Recepción cierra la cita sin historia escrita: la que ya terminó su hora y quedó sin cerrar (de hoy o de un
     * día anterior) o la de un doctor que no usa el panel. Mientras la visita sigue en curso con un doctor que sí
     * lo usa, cerrarla le toca a él (con su historia): para recepción queda como excepción, en "Más".
     */
    public static function complete(): Action
    {
        return self::closing(Action::make('complete'))
            ->label('Atendido')
            ->color('gray')
            ->visible(fn (Appointment $record) => self::closableWithoutHistory($record) && ! self::doctorIsStillWithIt($record));
    }

    /** La excepción: el doctor no va a registrar la atención y el paciente ya está en el mostrador para pagar. */
    public static function closeWithoutDoctor(): Action
    {
        return self::closing(Action::make('closeWithoutDoctor'))
            ->label('Cerrar sin el doctor')
            ->visible(fn (Appointment $record) => self::closableWithoutHistory($record) && self::doctorIsStillWithIt($record));
    }

    /**
     * Lo común de cerrar una cita sin historia: se pregunta antes si nadie anotó que llegó, si es de otro día o si
     * su doctor usa el panel (lo normal es que la cierre él).
     */
    private static function closing(Action $action): Action
    {
        return $action
            ->icon(Heroicon::OutlinedCheckBadge)
            ->requiresConfirmation(fn (Appointment $record) => $record->isFromAnEarlierDay() || $record->endedWithoutArriving() || self::doctorUsesThePanel($record))
            ->modalHeading(fn (Appointment $record) => $record->isFromAnEarlierDay() || $record->endedWithoutArriving()
                ? '¿El paciente vino el '.$record->starts_at->translatedFormat('l j \d\e F').'?'
                : '¿Cerrar la cita sin la atención del doctor?')
            ->modalDescription(fn (Appointment $record) => ($record->isFromAnEarlierDay() || $record->endedWithoutArriving()
                ? 'La cita queda atendida con su fecha y se registra lo programado: '.self::scheduled($record).'. Si no vino, usa "No asistió".'
                : 'Lo normal es que '.$record->loadMissing('doctor')->doctor->name.' la cierre al terminar. Si la cierras tú, se registra lo programado: '.self::scheduled($record).'.')
                .(self::doctorUsesThePanel($record) ? ' Queda sin historia clínica escrita.' : ''))
            ->modalSubmitActionLabel(fn (Appointment $record) => $record->isFromAnEarlierDay() || $record->endedWithoutArriving() ? 'Sí, se atendió' : 'Sí, cerrarla')
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->markCompleted($record, auth()->user()),
                'Cita atendida',
            ));
    }

    /** Lo que se carga al cerrar sin historia: la sesión de su plan o el tratamiento de la cita. */
    private static function scheduled(Appointment $record): string
    {
        return $record->planItem?->label(withTooth: false) ?? $record->treatment->name;
    }

    /** Puede cerrarla quien no es su doctor, mientras el doctor no haya abierto ya su atención. */
    private static function closableWithoutHistory(Appointment $record): bool
    {
        return (in_array($record->status, [AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true) || $record->endedWithoutArriving() || $record->isLeftOpen())
            && ! self::attendsClinically($record)
            && self::openConsultation($record) === null;
    }

    /** El paciente está en sala o en consulta, la hora de la cita no terminó y su doctor usa el panel. */
    private static function doctorIsStillWithIt(Appointment $record): bool
    {
        return in_array($record->status, [AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true)
            && ! $record->ends_at->isPast()
            && self::doctorUsesThePanel($record);
    }

    private static function doctorUsesThePanel(Appointment $record): bool
    {
        return $record->loadMissing('doctor')->doctor->user_id !== null;
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
            // Qué pasa después, en vez de un "¿Seguro?" que no dice nada.
            ->modalDescription('Cuenta como una falta del paciente y entra en "Faltaron o cancelaron" (en Pacientes), para darle otro horario. Si fue un error, tienes un día para deshacerlo.')
            ->modalSubmitActionLabel('Marcar inasistencia')
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->markNoShow($record, auth()->user()),
                'Marcada como inasistencia',
            ));
    }

    /**
     * Deshacer un paso marcado por error, durante un día. Va a la vista cuando la cita quedó como "No asistió" o
     * cancelada: ahí ya no queda otro botón, y es el toque equivocado que más cuesta (un paciente que sí vino).
     */
    public static function undo(): Action
    {
        return self::undoing('undo')
            ->visible(fn (Appointment $record) => in_array(self::undoableStep($record), AppointmentStatus::missed(), true));
    }

    /** En "Más": deshacer un "Llegó" o un "Confirmar" marcado por error. La cita sigue en curso y tiene sus botones del día. */
    public static function undoInMenu(): Action
    {
        return self::undoing('undoStep')
            ->visible(fn (Appointment $record) => in_array(self::undoableStep($record), [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true));
    }

    private static function undoing(string $name): Action
    {
        return Action::make($name)
            ->label(fn (Appointment $record) => self::undoLabel($record))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (Appointment $record) => '¿'.self::undoLabel($record).'?')
            ->modalDescription(fn (Appointment $record) => self::undoDescription($record))
            ->modalSubmitActionLabel('Sí, deshacer')
            ->modalCancelActionLabel('Volver')
            ->authorize('update')
            ->action(fn (Appointment $record) => Notify::run(
                fn () => app(AppointmentService::class)->undoLastStep($record, auth()->user()),
                'Listo, la cita volvió a como estaba',
            ));
    }

    private static function undoableStep(Appointment $record): ?AppointmentStatus
    {
        return app(AppointmentService::class)->undoableStep($record);
    }

    private static function undoLabel(Appointment $record): string
    {
        return match (self::undoableStep($record)) {
            AppointmentStatus::NoShow => 'Deshacer «No asistió»',
            AppointmentStatus::Cancelled => 'Deshacer cancelación',
            AppointmentStatus::CheckedIn => 'Deshacer «Llegó»',
            AppointmentStatus::Confirmed => 'Deshacer confirmación',
            default => 'Deshacer',
        };
    }

    /** Qué pasa al deshacer: a qué estado vuelve la cita y qué conviene revisar. */
    private static function undoDescription(Appointment $record): string
    {
        $before = $record->confirmed_at ? 'confirmada' : 'pendiente';

        return match (self::undoableStep($record)) {
            AppointmentStatus::NoShow => "La cita deja de contar como inasistencia y vuelve a quedar {$before}.",
            AppointmentStatus::Cancelled => 'La cita vuelve a la agenda el '.Dates::dayAndTime($record->starts_at).", {$before}, si ese horario sigue libre. Si ya le avisaste al paciente que se canceló, avísale que sigue en pie.",
            AppointmentStatus::CheckedIn => "El paciente deja de figurar en sala y la cita vuelve a quedar {$before}.",
            AppointmentStatus::Confirmed => 'La cita vuelve a quedar pendiente: falta que el paciente confirme.',
            default => 'La cita vuelve a como estaba antes.',
        };
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
                self::movedMessage(SlotPicker::startsAt($data), (bool) ($data['agreed'] ?? false)),
                $action,
            ));
    }

    /**
     * El paciente venía por una cosa y termina siendo otra, o el caso necesita más tiempo: se cambia aquí, sin
     * cancelar la cita ni crear otra. Hasta que pasa con el doctor; después, lo que se hizo queda en su atención.
     */
    public static function changeTreatment(): Action
    {
        return Action::make('changeTreatment')
            ->label('Cambiar tratamiento o duración')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->modalHeading('Cambiar tratamiento o duración')
            ->modalDescription(fn (Appointment $record) => 'La cita sigue el '.Dates::dayAndTime($record->starts_at).' con '.$record->doctor->name.': solo cambia qué se le hace o cuánto dura.')
            ->modalSubmitActionLabel('Guardar cambio')
            ->modalWidth(Width::Large)
            ->visible(fn (Appointment $record) => in_array($record->status, AppointmentStatus::notYetAttended(), true))
            ->authorize('update')
            ->fillForm(fn (Appointment $record) => [
                'treatment_id' => $record->treatment_id,
                'duration_minutes' => $record->durationMinutes(),
            ])
            ->schema(fn (Appointment $record) => [
                Select::make('treatment_id')
                    ->label('Tratamiento')
                    ->options(fn () => Treatment::query()
                        ->where(fn (Builder $offered) => $offered
                            ->whereKey($record->treatment_id)
                            ->orWhere(fn (Builder $active) => $active->active()->whereHas('doctors', fn (Builder $doctor) => $doctor->whereKey($record->doctor_id))))
                        ->orderBy('sort_order')
                        ->pluck('name', 'id'))
                    ->helperText(fn (Get $get) => $record->treatment_plan_item_id && (int) $get('treatment_id') !== $record->treatment_id
                        ? 'Esta cita era una sesión de su plan de tratamiento. Con otro tratamiento deja de contar como esa sesión.'
                        : 'Solo los que realiza '.$record->doctor->name.'.')
                    ->searchable()
                    ->required()
                    ->live()
                    // Con otro tratamiento, la duración pasa a ser la normal de ese.
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('duration_minutes', ($treatment = Treatment::find($state)) ? $record->doctor->durationFor($treatment) : null)),
                Select::make('duration_minutes')
                    ->label('Duración')
                    ->options(fn (Get $get) => SlotPicker::durationOptions(
                        ($treatment = Treatment::find($get('treatment_id'))) ? $record->doctor->durationFor($treatment) : null,
                        $record->durationMinutes(),
                    ))
                    ->helperText(fn (Get $get) => filled($get('duration_minutes'))
                        ? 'De '.$record->starts_at->format('H:i').' a '.$record->starts_at->copy()->addMinutes((int) $get('duration_minutes'))->format('H:i').'.'
                        : null)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(),
            ])
            ->action(fn (array $data, Appointment $record, Action $action) => Notify::run(
                fn () => app(AppointmentService::class)->changeTreatment($record, auth()->user(), Treatment::findOrFail($data['treatment_id']), (int) $data['duration_minutes']),
                'Cita actualizada',
                $action,
            ));
    }

    /**
     * Las notas internas ("viene con su mamá") y la fecha fija, desde cualquier lugar donde se ve la cita: antes solo
     * se podían editar en su página.
     */
    public static function notes(): Action
    {
        return Action::make('notes')
            ->label('Editar notas')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading('Notas de la cita')
            ->modalSubmitActionLabel('Guardar')
            ->modalWidth(Width::Large)
            ->visible(fn (Appointment $record) => $record->status !== AppointmentStatus::Cancelled)
            ->authorize('update')
            ->fillForm(fn (Appointment $record) => $record->only(['internal_notes', 'fixed_date']))
            ->schema(fn (Appointment $record) => [
                Textarea::make('internal_notes')
                    ->label('Notas internas')
                    ->helperText('Solo las ve el personal. En la agenda, la cita muestra la etiqueta "Nota".')
                    ->rows(3)
                    ->autofocus(),
                Toggle::make('fixed_date')
                    ->label('Fecha fija (el paciente no la mueve solo)')
                    ->visible($record->status->isChangeable()),
            ])
            ->action(function (array $data, Appointment $record): void {
                $record->update($data);

                Notification::make()->success()->title('Notas guardadas')->send();
            });
    }

    /** Lo que se dice al mover una cita: si quedó confirmada, si hay un aviso por enviar o si se movió a una hora que ya pasó. */
    public static function movedMessage(CarbonInterface $startsAt, bool $agreed): string
    {
        return match (true) {
            $startsAt->isPast() => 'Cita movida a una hora que ya pasó. No se preparó aviso al paciente.',
            $agreed => 'Cita movida y confirmada.',
            default => 'Cita movida. El aviso al paciente está listo en Avisos.',
        };
    }

    /**
     * Cancelar dice quién cancela y por qué, con un toque (así se sabe por qué se pierden las citas), y no deja al
     * paciente suelto: se le puede agendar otra cita ahí mismo o pasarlo a la lista de espera.
     */
    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar cita')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Cancelar cita')
            ->modalDescription('Si solo quiere otro día u otra hora, usa "Mover cita": la cita se conserva y no cuenta como cancelación.')
            ->modalSubmitActionLabel('Cancelar la cita')
            ->modalCancelActionLabel('Volver')
            ->modalWidth(Width::Large)
            ->visible(fn (Appointment $record) => $record->status->isChangeable())
            ->authorize('update')
            ->schema(fn (Appointment $record) => [
                ToggleButtons::make('who')
                    ->label('¿Quién cancela?')
                    ->options([CancelledBy::Patient->value => 'El paciente', CancelledBy::Clinic->value => 'El consultorio'])
                    ->inline()
                    ->required()
                    ->validationMessages(['required' => 'Marca si cancela el paciente o el consultorio.'])
                    ->live()
                    // El paciente que cancela ya lo sabe; si cancela el consultorio, hay que avisarle.
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $set('motive', null);
                        $set('notify', $state === CancelledBy::Clinic->value);
                    }),
                ToggleButtons::make('motive')
                    ->label('Motivo')
                    ->options(fn (Get $get) => array_combine(self::cancelMotives($get('who')), self::cancelMotives($get('who'))))
                    ->inline()
                    ->visible(fn (Get $get) => filled($get('who'))),
                Textarea::make('reason')
                    ->label('Detalle')
                    ->placeholder('Opcional')
                    ->rows(2)
                    ->maxLength(200),
                Toggle::make('notify')
                    ->label('Avisar al paciente por WhatsApp')
                    ->default(true),
                Checkbox::make('series')
                    ->label(fn () => match ($later = Appointment::query()->laterInSeries($record)->count()) {
                        1 => 'Cancelar también su cita siguiente de la serie',
                        default => "Cancelar también sus {$later} citas siguientes de la serie",
                    })
                    ->helperText('Se agendaron juntas, para repetirse cada tantas semanas.')
                    ->visible(fn () => $record->series_id !== null && Appointment::query()->laterInSeries($record)->exists()),
                Radio::make('then')
                    ->label('¿Y después?')
                    ->options(array_filter([
                        'nothing' => 'Nada más',
                        'rebook' => AppointmentResource::canCreate() ? 'Agendarle otra cita ahora' : null,
                        'waitlist' => auth()->user()->can('create', WaitlistEntry::class) ? 'Pasarlo a la lista de espera' : null,
                    ]))
                    ->descriptions([
                        'rebook' => 'Se abre "Nueva cita" con el paciente, el doctor y el tratamiento ya puestos.',
                        'waitlist' => 'Queda anotado para ese tratamiento: llega la alerta cuando se libere un horario.',
                    ])
                    ->default('nothing'),
            ])
            ->action(function (array $data, Appointment $record, Action $action): void {
                /** @var User $user */
                $user = auth()->user();
                $cancelled = 0;

                Notify::run(
                    function () use ($data, $record, $user, &$cancelled): void {
                        $cancelled = app(AppointmentService::class)->cancelByStaff(
                            $record,
                            $user,
                            self::cancelReason($data),
                            (bool) ($data['notify'] ?? false),
                            CancelledBy::tryFrom((string) ($data['who'] ?? '')) ?? CancelledBy::Clinic,
                            (bool) ($data['series'] ?? false),
                        );
                    },
                    'Cita cancelada',
                    $action,
                    function (Notification $notification) use (&$cancelled): Notification {
                        return $cancelled > 1 ? $notification->title($cancelled.' citas canceladas') : $notification;
                    },
                );

                match ($data['then'] ?? 'nothing') {
                    'rebook' => $action->redirect(AppointmentResource::newUrl([
                        'patient' => $record->patient_id,
                        'doctor' => $record->doctor_id,
                        'treatment' => $record->treatment_id,
                        'plan_item' => $record->treatment_plan_item_id,
                    ])),
                    'waitlist' => self::passToWaitlist($record, $user),
                    default => null,
                };
            });
    }

    /**
     * Los motivos de un toque, según quién cancela.
     *
     * @return list<string>
     */
    public static function cancelMotives(?string $who): array
    {
        return match (CancelledBy::tryFrom((string) $who)) {
            CancelledBy::Patient => ['No puede venir ese día', 'Está enfermo', 'Por el costo', 'Ya no quiere el tratamiento', 'Otro'],
            CancelledBy::Clinic => ['El doctor no podrá atender', 'No se atiende ese día', 'Se agendó por error', 'Otro'],
            default => [],
        };
    }

    /**
     * El motivo que queda guardado: el de un toque y, si se escribió, el detalle ("Está enfermo: tiene fiebre").
     *
     * @param  array<string, mixed>  $data
     */
    private static function cancelReason(array $data): ?string
    {
        $motive = ($data['motive'] ?? null) === 'Otro' && filled($data['reason'] ?? null) ? null : ($data['motive'] ?? null);

        return collect([$motive, $data['reason'] ?? null])->filter()->implode(': ') ?: null;
    }

    /** Tras cancelar: el paciente queda en la lista de espera (si no estaba ya) y se dice dónde verlo. */
    private static function passToWaitlist(Appointment $record, User $by): void
    {
        if (! $by->can('create', WaitlistEntry::class)) {
            return;
        }

        $entry = app(WaitlistService::class)->addAfterCancellation($record, $by);

        Notification::make()
            ->success()
            ->title($record->patient->full_name.($entry ? ' quedó en la lista de espera' : ' ya estaba en la lista de espera'))
            ->body($entry ? 'Para '.mb_strtolower($record->treatment->name).', desde hoy y a cualquier hora. Si pidió algo más preciso, ajústalo en la lista.' : null)
            ->actions([Action::make('seeWaitlist')->label('Ver la lista de espera')->url(WaitlistEntryResource::getUrl())])
            ->send();
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

    /**
     * Escribirle al paciente de la cita. Abre su aviso pendiente si lo tiene; si no, el mensaje que corresponde
     * a cómo está la cita (ver whatsappDraft), que se guarda recién al enviarlo: abrir la ventana y cerrarla no
     * deja nada en Avisos. Cuando no hay nada que decirle de esta cita (ya vino, o su hora ya pasó), abre el chat
     * en blanco: no se le anuncia como "tu próxima cita" una que ya fue.
     */
    public static function whatsapp(): Action
    {
        return SendWhatsAppAction::compose('whatsapp', fn (Appointment $record) => self::whatsappDraft($record))
            ->label('WhatsApp')
            ->modalHeading(fn (Appointment $record) => 'WhatsApp para '.$record->patient->full_name)
            ->modalDescription(fn (Appointment $record) => Phone::display($record->patient->account->phone)
                .'. Revisa el mensaje. Se abrirá WhatsApp con el texto listo: solo presiona Enviar. Si lleva {enlace}, se cambia por su enlace personal al abrirlo.')
            ->url(
                fn (Appointment $record) => self::hasNoticeToSend($record) ? null : 'https://wa.me/'.Phone::forWhatsApp($record->patient->account->phone),
                shouldOpenInNewTab: true,
            )
            ->visible(fn (Appointment $record) => $record->patient->account_id !== null)
            ->authorize('update');
    }

    /**
     * El aviso que toca según la cita: si aún no empieza, sus datos con el enlace para confirmar; si el paciente
     * faltó o se canceló, la invitación a tomar otro horario; recién atendida, sus cuidados si siguen en Avisos.
     * Siempre gana el que ya espera en Avisos. Null si no toca ninguno.
     */
    private static function whatsappDraft(Appointment $record): ?WhatsappMessage
    {
        $template = self::whatsappTemplate($record);

        if ($template === null && ! self::mayHaveAftercareWaiting($record)) {
            return null;
        }

        return $record->messages()->pending()->latest('id')->first()
            ?? ($template ? app(WhatsAppOutbox::class)->draftForAppointment($record, $template) : null);
    }

    /** Lo mismo que whatsappDraft, sin armar el mensaje: lo pregunta cada fila de una lista para saber qué abre su botón. */
    private static function hasNoticeToSend(Appointment $record): bool
    {
        return self::whatsappTemplate($record) !== null
            || (self::mayHaveAftercareWaiting($record) && $record->messages()->pending()->exists());
    }

    private static function whatsappTemplate(Appointment $record): ?MessageTemplateKey
    {
        return match (true) {
            $record->status->isChangeable() && $record->starts_at->isFuture() => MessageTemplateKey::AppointmentScheduled,
            in_array($record->status, AppointmentStatus::missed(), true) => MessageTemplateKey::MissedVisit,
            default => null,
        };
    }

    /** Atendida hace poco y su tratamiento tiene cuidados: ese aviso puede seguir esperando en Avisos. */
    private static function mayHaveAftercareWaiting(Appointment $record): bool
    {
        return $record->status === AppointmentStatus::Completed
            && $record->finished_at?->gte(now()->subDays(2))
            && filled($record->loadMissing('treatment')->treatment->aftercare);
    }
}
