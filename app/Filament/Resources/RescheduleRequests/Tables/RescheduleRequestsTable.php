<?php

namespace App\Filament\Resources\RescheduleRequests\Tables;

use App\Enums\RescheduleRequestStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Support\Notify;
use App\Filament\Support\SlotPicker;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\RescheduleRequest;
use App\Models\RescheduleRequestOption;
use App\Services\Agenda\AvailabilityService;
use App\Services\Booking\RescheduleRequestService;
use App\Support\Dates;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use WeakMap;

class RescheduleRequestsTable
{
    /** @var WeakMap<RescheduleRequest, array<int, string|null>>|null Por qué no sirve cada horario propuesto, ya calculado en este pedido. */
    private static ?WeakMap $problems = null;

    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('created_at')
                    ->label('Pedida')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('appointment.patient.first_name')
                    ->label('Paciente')
                    ->formatStateUsing(fn (RescheduleRequest $record) => $record->appointment->patient->full_name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('appointment.patient', fn (Builder $patient) => $patient->search($search)))
                    ->description(fn (RescheduleRequest $record) => $record->appointment->patient->account ? Phone::display($record->appointment->patient->account->phone) : null),
                TextColumn::make('appointment.starts_at')
                    ->label('Cita actual')
                    ->dateTime('D d/m H:i')
                    ->description(fn (RescheduleRequest $record) => $record->appointment->treatment->name.' · '.$record->appointment->doctor->name)
                    ->wrap(),
                TextColumn::make('options_list')
                    ->label('Horarios que propone')
                    // Mientras está pendiente se dice cuál ya no sirve y por qué: así se ve sin abrir "Aprobar".
                    ->state(fn (RescheduleRequest $record) => $record->options->map(fn (RescheduleRequestOption $o) => self::optionLabel($o, $record).self::problemSuffix($o, $record))->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    // El motivo va debajo y no en una columna propia: en una laptop esa columna sacaba de la pantalla
                    // los botones "Aprobar" y "No aprobar".
                    ->description(fn (RescheduleRequest $record) => filled($record->reason) ? 'Motivo: '.Str::limit($record->reason, 120) : null)
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    // Quién la resolvió y, si se cerró sola o se le dio otro horario, qué pasó.
                    ->description(fn (RescheduleRequest $record) => collect([
                        $record->resolvedBy ? $record->resolvedBy->name.' · '.$record->resolved_at?->format('d/m H:i') : null,
                        $record->status === RescheduleRequestStatus::Rejected ? null : $record->response_note,
                    ])->filter()->implode(' · ') ?: null)
                    ->wrap()
                    // En "Pendientes" todas dicen lo mismo: la columna solo ocupa sitio.
                    ->hidden(fn (HasTable $livewire) => ($livewire->activeTab ?? null) === 'pending'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (RescheduleRequest $record) => AppointmentResource::getUrl('view', ['record' => $record->appointment_id]))
            ->recordActions([
                self::approveAction(),
                // Si ninguno de sus horarios sirve, darle otro pasa a ser el botón de la fila.
                self::offerAnotherTimeAction('offerAnotherTime')
                    ->button()
                    ->color('primary')
                    ->visible(fn (RescheduleRequest $record) => self::isPending($record) && ! self::hasUsableOption($record)),
                ActionGroup::make([
                    self::offerAnotherTimeAction('offerAnotherTimeInstead')
                        ->visible(fn (RescheduleRequest $record) => self::isPending($record) && self::hasUsableOption($record)),
                    self::rejectAction(),
                ]),
            ])
            ->poll('30s')
            ->emptyStateHeading('No hay cambios pedidos')
            ->emptyStateDescription('Cuando un paciente proponga otros horarios, aparecerán aquí para aprobar uno.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprobar')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->button()
            // Sin ningún horario que sirva no hay nada que aprobar: queda "Proponer otro horario".
            ->visible(fn (RescheduleRequest $record) => self::isPending($record) && self::hasUsableOption($record))
            ->authorize('update')
            ->modalHeading('Aprobar cambio de horario')
            ->modalDescription('Elige uno de los horarios que propuso el paciente. Se moverá la cita y quedará listo el aviso para enviarle.')
            ->modalSubmitActionLabel('Aprobar este horario')
            ->schema(fn (RescheduleRequest $record) => [
                Radio::make('option_id')
                    ->label('Horario')
                    ->required()
                    ->options(fn () => self::approvableOptions($record))
                    ->disableOptionWhen(fn (string $value) => ! self::optionIsFree($record->options->firstWhere('id', (int) $value), $record))
                    ->default(fn () => $record->options->first(fn (RescheduleRequestOption $o) => self::optionIsFree($o, $record))?->id),
            ])
            ->action(fn (array $data, RescheduleRequest $record, Action $action) => Notify::run(
                fn () => app(RescheduleRequestService::class)->approve(
                    $record,
                    $record->options->firstWhere('id', (int) $data['option_id']),
                    auth()->user(),
                ),
                'Cambio aprobado. El aviso al paciente está listo en Avisos.',
                $action,
            ));
    }

    /**
     * Darle un horario distinto de los que propuso, con los mismos horarios libres de "Mover cita": la cita se
     * mueve ahí y la solicitud queda resuelta.
     */
    private static function offerAnotherTimeAction(string $name): Action
    {
        return Action::make($name)
            ->label('Proponer otro horario')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->authorize('update')
            ->modalHeading('Proponer otro horario')
            ->modalDescription(fn (RescheduleRequest $record) => 'Su cita ahora: '.Dates::dayAndTime($record->appointment->starts_at).'. Elige un horario libre: la cita se mueve ahí y la solicitud queda resuelta.')
            ->modalSubmitActionLabel('Mover la cita')
            ->fillForm(fn (RescheduleRequest $record) => [
                'doctor_id' => $record->requested_doctor_id ?? $record->appointment->doctor_id,
                'date' => $record->appointment->starts_at->max(today())->toDateString(),
            ])
            ->schema(fn (RescheduleRequest $record) => [
                ...SlotPicker::fields(self::appointmentAsRequested($record)),
                Checkbox::make('agreed')
                    ->label('Ya lo acordé con el paciente')
                    ->helperText('Queda confirmada y no se le pide confirmar. Si no, se prepara el aviso para que confirme.'),
            ])
            ->action(fn (array $data, RescheduleRequest $record, Action $action) => Notify::run(
                fn () => app(RescheduleRequestService::class)->offerAnotherTime(
                    $record,
                    SlotPicker::startsAt($data),
                    auth()->user(),
                    Doctor::findOrFail($data['doctor_id']),
                    (bool) ($data['agreed'] ?? false),
                ),
                ($data['agreed'] ?? false) ? 'Cita movida y confirmada. La solicitud quedó resuelta.' : 'Cita movida. El aviso para que confirme está listo en Avisos.',
                $action,
            ));
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('No aprobar')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (RescheduleRequest $record) => self::isPending($record))
            ->authorize('update')
            ->modalHeading('No aprobar el cambio')
            ->modalDescription('La cita se queda en su horario actual y se prepara un aviso para el paciente.')
            ->modalSubmitActionLabel('No aprobar')
            ->schema([
                Textarea::make('note')
                    ->label('Nota interna (opcional)')
                    ->rows(2)
                    ->maxLength(250),
            ])
            ->action(fn (array $data, RescheduleRequest $record) => Notify::run(
                fn () => app(RescheduleRequestService::class)->reject($record, auth()->user(), $data['note'] ?? null),
                'Solicitud no aprobada. El aviso al paciente está listo en Avisos.',
            ));
    }

    private static function isPending(RescheduleRequest $request): bool
    {
        return $request->status === RescheduleRequestStatus::Pending;
    }

    /** @return array<int, string> */
    private static function approvableOptions(RescheduleRequest $request): array
    {
        return $request->options
            ->mapWithKeys(fn (RescheduleRequestOption $o) => [$o->id => self::optionLabel($o, $request).self::problemSuffix($o, $request)])
            ->all();
    }

    private static function optionLabel(RescheduleRequestOption $option, RescheduleRequest $request): string
    {
        $label = ucfirst($option->starts_at->translatedFormat('D d/m H:i'));

        return $option->doctor_id !== $request->appointment->doctor_id ? $label.' · '.$option->doctor->name : $label;
    }

    /** " (ya está ocupado)", " (ese día no se atiende)"… o nada si el horario sirve o la solicitud ya se resolvió. */
    private static function problemSuffix(RescheduleRequestOption $option, RescheduleRequest $request): string
    {
        $problem = self::isPending($request) ? self::optionProblems($request)[$option->getKey()] ?? null : null;

        return $problem ? " ({$problem})" : '';
    }

    private static function hasUsableOption(RescheduleRequest $request): bool
    {
        return in_array(null, self::optionProblems($request), true);
    }

    /** Desde que el paciente propuso, otra persona pudo ocupar ese horario. */
    private static function optionIsFree(?RescheduleRequestOption $option, RescheduleRequest $request): bool
    {
        $problems = self::optionProblems($request);

        return $option !== null && array_key_exists($option->getKey(), $problems) && $problems[$option->getKey()] === null;
    }

    /**
     * Por qué ya no sirve cada horario que propuso el paciente (null si sigue sirviendo). Se calcula una vez por
     * solicitud y pedido: lo consultan la columna y los botones de la fila.
     *
     * @return array<int, string|null> Por id del horario propuesto.
     */
    private static function optionProblems(RescheduleRequest $request): array
    {
        self::$problems ??= new WeakMap;

        return self::$problems[$request] ??= $request->options
            ->mapWithKeys(fn (RescheduleRequestOption $option) => [$option->getKey() => self::optionProblem($option, $request)])
            ->all();
    }

    private static function optionProblem(RescheduleRequestOption $option, RescheduleRequest $request): ?string
    {
        if ($option->starts_at->isPast()) {
            return 'ya pasó';
        }

        $appointment = $request->appointment;
        $treatment = $request->requestedTreatment ?? $appointment->treatment;
        $availability = app(AvailabilityService::class)->forPatient($appointment->patient_id);
        $duration = (int) $option->starts_at->diffInMinutes($option->ends_at, true);

        if ($availability->isFree($option->doctor, $option->starts_at, $duration, $treatment->bufferMinutes(), $appointment->getKey())) {
            return null;
        }

        $hours = $availability->workingRanges($option->doctor, $option->starts_at);

        return match (true) {
            $hours->isEmpty() => 'ese día no se atiende',
            ! $hours->contains(fn (array $range) => $option->starts_at->gte($range['start']) && $option->ends_at->lte($range['end'])) => 'fuera del horario de atención',
            default => 'ya está ocupado',
        };
    }

    /** La cita como quedaría con lo que pidió el paciente: los horarios libres se buscan para ese tratamiento. */
    private static function appointmentAsRequested(RescheduleRequest $request): Appointment
    {
        $appointment = clone $request->appointment;

        if ($request->requested_treatment_id) {
            $appointment->treatment_id = $request->requested_treatment_id;
        }

        return $appointment;
    }
}
