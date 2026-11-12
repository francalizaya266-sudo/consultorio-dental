<?php

namespace App\Filament\Resources\WaitlistEntries;

use App\Enums\WaitlistStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\WaitlistEntry;
use App\Services\Agenda\Slot;
use App\Services\Booking\WaitlistService;
use App\Services\WhatsApp\WhatsAppOutbox;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/** Anotarlo en la lista, ofrecerle un horario por WhatsApp (queda apartado mientras responde), agendarlo cuando dice que sí, liberarlo o retirarlo. */
final class WaitlistEntryActions
{
    /**
     * Anotar a alguien en la lista: la misma ventana en la lista de espera y en "Hoy", sin salir de donde se está.
     * El aviso dice qué pasa después, porque quien anota no vuelve a mirar la lista hasta que se libera un horario.
     */
    public static function add(string $name = 'create'): CreateAction
    {
        return CreateAction::make($name)
            ->model(WaitlistEntry::class)
            ->label('Anotar paciente')
            ->visible(fn () => auth()->user()->can('create', WaitlistEntry::class))
            ->modalHeading('Anotar en lista de espera')
            ->modalDescription('Para quien quiere un horario antes o un día que hoy está lleno. Cuando se libere uno que le sirva, llega la alerta para ofrecérselo.')
            ->modalSubmitActionLabel('Anotar')
            ->modalWidth(Width::ThreeExtraLarge)
            ->schema(fn (Schema $schema) => WaitlistEntryResource::form($schema))
            ->createAnother(false)
            ->mutateDataUsing(fn (array $data) => $data + [
                'status' => WaitlistStatus::Waiting,
                'created_by_user_id' => auth()->id(),
            ])
            ->successNotification(fn (Notification $notification, WaitlistEntry $record) => $notification
                ->title($record->patient->full_name.' quedó en la lista de espera')
                ->body('Cuando se libere un horario que le sirva, llegará la alerta para ofrecérselo.'));
    }

    /**
     * Llega con el primer horario libre que le sirve (o con el que se tocó en la agenda). Al cambiar el doctor, el día o la hora, el
     * mensaje se vuelve a escribir; al abrir WhatsApp queda como avisado con ese horario.
     */
    public static function offer(): Action
    {
        return Action::make('offer')
            ->label('Ofrecer horario')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('success')
            ->visible(fn (WaitlistEntry $record) => $record->isActive() && $record->patient->account !== null)
            ->authorize('update')
            ->modalHeading(fn (WaitlistEntry $record) => 'Ofrecer un horario a '.$record->patient->full_name)
            ->modalDescription('Elige el horario. Se abrirá WhatsApp con el mensaje listo: solo presiona Enviar. El horario queda apartado mientras responde: no se le ofrece a nadie más ni se puede reservar en la web.')
            ->modalSubmitActionLabel('Abrir WhatsApp')
            ->modalWidth(Width::TwoExtraLarge)
            ->fillForm(function (WaitlistEntry $record, array $arguments) {
                $service = app(WaitlistService::class);
                $first = $service->firstSlot($record);
                $state = [
                    'treatment_id' => $record->treatment_id,
                    'doctor_id' => $first['doctor']->id ?? $record->doctor_id ?? $service->doctorsFor($record)->first()?->id,
                    'date' => ($first['slot']->startsAt ?? $record->firstDate())->toDateString(),
                    'slot' => $first ? $first['slot']->label() : null,
                ];

                // Desde la agenda llega la hora que se vio libre ese día.
                if (isset($arguments['doctor'], $arguments['date'], $arguments['time'])) {
                    $state = ['doctor_id' => (int) $arguments['doctor'], 'date' => (string) $arguments['date'], 'slot' => (string) $arguments['time']] + $state;
                }

                return $state + ['body' => self::body($record, $state)];
            })
            ->schema(fn (WaitlistEntry $record) => [
                Select::make('treatment_id')
                    ->label('Tratamiento')
                    ->options(fn () => Treatment::query()->active()->whereHas('doctors')->orderBy('sort_order')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->hidden($record->treatment_id !== null)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::refresh($record, $get, $set, keepSlot: false)),
                Select::make('doctor_id')
                    ->label('Doctor')
                    ->options(fn (Get $get) => app(WaitlistService::class)->doctorsFor($record, Treatment::find($get('treatment_id')))->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::refresh($record, $get, $set, keepSlot: false)),
                DatePicker::make('date')
                    ->label('Día')
                    ->native(false)
                    ->displayFormat('D d/m/Y')
                    ->firstDayOfWeek(1)
                    ->closeOnDateSelection()
                    ->minDate($record->firstDate())
                    ->maxDate($record->latest_date)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::refresh($record, $get, $set, keepSlot: false)),
                Select::make('slot')
                    ->label('Horario libre')
                    ->options(fn (Get $get) => self::slotOptions($record, $get))
                    ->placeholder(fn (Get $get) => self::slotOptions($record, $get) === [] ? 'No hay horarios libres que le sirvan ese día' : 'Elige un horario')
                    ->helperText('Solo los que caen en la parte del día que pidió. ★ = pegado a otra cita.')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::refresh($record, $get, $set, keepSlot: true)),
                Textarea::make('body')
                    ->label('Mensaje')
                    ->rows(6)
                    ->required(),
            ])
            ->action(function (array $data, WaitlistEntry $record, Action $action, Component $livewire) {
                $service = app(WaitlistService::class);
                $doctor = Doctor::findOrFail($data['doctor_id']);
                $treatment = Treatment::findOrFail($data['treatment_id'] ?? $record->treatment_id);
                $startsAt = CarbonImmutable::parse($data['date'])->setTimeFromTimeString($data['slot']);

                // Mientras se escribía, el horario pudo ocuparse.
                $stillFree = $service->slotsFor($record, $doctor, $treatment, $startsAt)
                    ->contains(fn (Slot $slot) => $slot->startsAt->eq($startsAt));

                if (! $stillFree) {
                    Notification::make()->danger()->title('Ese horario ya no está libre. Elige otro.')->send();
                    $action->halt();
                }

                $message = $service->offerDraft($record, $doctor, $treatment, $startsAt);

                if (! $message) {
                    Notification::make()->danger()->title('El paciente no tiene celular registrado.')->send();
                    $action->halt();
                }

                $message->save();
                $url = app(WhatsAppOutbox::class)->clickToChatUrl($message, auth()->user(), $data['body']);
                $service->markOffered($record, $doctor, $treatment, $startsAt);

                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }

    /**
     * Si ya tiene una cita para lo mismo, se le adelanta; si no, se abre "Nueva cita" con el paciente,
     * el tratamiento y el horario que se le ofreció. Al agendarla sale solo de la lista.
     */
    public static function book(): Action
    {
        return Action::make('book')
            ->label(fn (WaitlistEntry $record) => $record->upcoming_appointment_id !== null ? 'Adelantar cita' : 'Agendar')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (WaitlistEntry $record) => $record->isActive())
            ->url(fn (WaitlistEntry $record) => $record->upcoming_appointment_id !== null
                ? AppointmentResource::getUrl('view', ['record' => $record->upcoming_appointment_id])
                : AppointmentResource::newUrl(array_filter([
                    'patient' => $record->patient_id,
                    'treatment' => $record->treatment_id,
                    'doctor' => $record->offered_doctor_id ?? $record->doctor_id,
                    'date' => ($record->offered_starts_at?->isFuture() ? $record->offered_starts_at : $record->firstDate())->toDateString(),
                    'time' => $record->offered_starts_at?->isFuture() ? $record->offered_starts_at->format('H:i') : null,
                ])));
    }

    /** No quiere el horario ofrecido (o ya se sabe que no viene): queda libre para otro y él sigue en la lista. */
    public static function release(): Action
    {
        return Action::make('release')
            ->label('Liberar el horario')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->visible(fn (WaitlistEntry $record) => $record->isHolding())
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(fn (WaitlistEntry $record) => '¿Liberar el horario apartado para '.$record->patient->first_name.'?')
            ->modalDescription('Queda libre para ofrecérselo a otro o para la web. Sigue en la lista de espera.')
            ->modalSubmitActionLabel('Liberar')
            ->action(function (WaitlistEntry $record) {
                app(WaitlistService::class)->release($record);

                Notification::make()->success()->title('Horario liberado')->send();
            });
    }

    public static function remove(): Action
    {
        return Action::make('remove')
            ->label('Retirar de la lista')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (WaitlistEntry $record) => $record->isActive())
            ->authorize('update')
            ->modalHeading('Retirar de la lista de espera')
            ->modalSubmitActionLabel('Retirar')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->placeholder('Ej.: ya no puede, consiguió otro horario.')
                    ->rows(2)
                    ->maxLength(200),
            ])
            ->action(function (array $data, WaitlistEntry $record) {
                app(WaitlistService::class)->remove($record, $data['reason'] ?? null);

                Notification::make()->success()->title('Retirado de la lista de espera')->send();
            });
    }

    /** Al cambiar algo, la hora pasa a la primera libre (si la elegida ya no sirve) y el mensaje se reescribe. */
    private static function refresh(WaitlistEntry $entry, Get $get, Set $set, bool $keepSlot): void
    {
        $options = self::slotOptions($entry, $get);

        if (! $keepSlot || ! array_key_exists((string) $get('slot'), $options)) {
            $set('slot', array_key_first($options));
        }

        $set('body', self::body($entry, [
            'treatment_id' => $get('treatment_id') ?? $entry->treatment_id,
            'doctor_id' => $get('doctor_id'),
            'date' => $get('date'),
            'slot' => $get('slot'),
        ]));
    }

    /** @return array<string, string> */
    private static function slotOptions(WaitlistEntry $entry, Get $get): array
    {
        $doctor = Doctor::find($get('doctor_id'));
        $treatment = Treatment::find($get('treatment_id') ?? $entry->treatment_id);
        $date = $get('date');

        if (! $doctor || ! $treatment || blank($date)) {
            return [];
        }

        return app(WaitlistService::class)
            ->slotsFor($entry, $doctor, $treatment, CarbonImmutable::parse($date))
            ->mapWithKeys(fn (Slot $slot) => [$slot->label() => $slot->label().($slot->recommended ? ' ★' : '')])
            ->all();
    }

    /**
     * El mensaje con el horario elegido, o vacío si falta elegirlo.
     *
     * @param  array{treatment_id: mixed, doctor_id: mixed, date: mixed, slot: mixed}  $state
     */
    private static function body(WaitlistEntry $entry, array $state): string
    {
        $doctor = Doctor::find($state['doctor_id']);
        $treatment = Treatment::find($state['treatment_id']);

        if (! $doctor || ! $treatment || blank($state['date']) || blank($state['slot'])) {
            return '';
        }

        $startsAt = CarbonImmutable::parse($state['date'])->setTimeFromTimeString($state['slot']);
        $message = app(WaitlistService::class)->offerDraft($entry, $doctor, $treatment, $startsAt);

        return $message ? app(WhatsAppOutbox::class)->render($message, auth()->user()) : '';
    }
}
