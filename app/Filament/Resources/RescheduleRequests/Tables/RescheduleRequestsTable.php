<?php

namespace App\Filament\Resources\RescheduleRequests\Tables;

use App\Enums\RescheduleRequestStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Support\Notify;
use App\Models\RescheduleRequest;
use App\Models\RescheduleRequestOption;
use App\Services\Agenda\AvailabilityService;
use App\Services\Booking\RescheduleRequestService;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RescheduleRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Pedida')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('appointment.patient.first_name')
                    ->label('Paciente')
                    ->formatStateUsing(fn (RescheduleRequest $record) => $record->appointment->patient->full_name)
                    ->description(fn (RescheduleRequest $record) => $record->appointment->patient->account ? Phone::display($record->appointment->patient->account->phone) : null),
                TextColumn::make('appointment.starts_at')
                    ->label('Cita actual')
                    ->dateTime('D d/m H:i')
                    ->description(fn (RescheduleRequest $record) => $record->appointment->treatment->name.' · '.$record->appointment->doctor->name),
                TextColumn::make('options_list')
                    ->label('Horarios que propone')
                    ->state(fn (RescheduleRequest $record) => $record->options->map(fn (RescheduleRequestOption $o) => self::optionLabel($o, $record))->all())
                    ->listWithLineBreaks()
                    ->bulleted(),
                TextColumn::make('reason')
                    ->label('Motivo')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->description(fn (RescheduleRequest $record) => $record->resolvedBy ? $record->resolvedBy->name.' · '.$record->resolved_at?->format('d/m H:i') : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (RescheduleRequest $record) => AppointmentResource::getUrl('view', ['record' => $record->appointment_id]))
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
            ])
            ->poll('30s')
            ->emptyStateHeading('No hay solicitudes de cambio')
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
            ->visible(fn (RescheduleRequest $record) => $record->status === RescheduleRequestStatus::Pending)
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
                'Cambio aprobado. El aviso al paciente está listo en "Avisos por enviar".',
                $action,
            ));
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('No aprobar')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (RescheduleRequest $record) => $record->status === RescheduleRequestStatus::Pending)
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
                'Solicitud no aprobada. El aviso al paciente está listo en "Avisos por enviar".',
            ));
    }

    /** @return array<int, string> */
    private static function approvableOptions(RescheduleRequest $request): array
    {
        return $request->options
            ->mapWithKeys(fn (RescheduleRequestOption $o) => [
                $o->id => self::optionLabel($o, $request).(self::optionIsFree($o, $request) ? '' : ' (ya está ocupado)'),
            ])
            ->all();
    }

    private static function optionLabel(RescheduleRequestOption $option, RescheduleRequest $request): string
    {
        $label = ucfirst($option->starts_at->translatedFormat('D d/m H:i'));

        return $option->doctor_id !== $request->appointment->doctor_id ? $label.' · '.$option->doctor->name : $label;
    }

    /** Desde que el paciente propuso, otra persona pudo ocupar ese horario. */
    private static function optionIsFree(?RescheduleRequestOption $option, RescheduleRequest $request): bool
    {
        if (! $option || $option->starts_at->isPast()) {
            return false;
        }

        $appointment = $request->appointment;
        $treatment = $request->requestedTreatment ?? $appointment->treatment;

        return app(AvailabilityService::class)->isFree(
            $option->doctor,
            $option->starts_at,
            (int) $option->starts_at->diffInMinutes($option->ends_at, true),
            $treatment->bufferMinutes(),
            $appointment->getKey(),
        );
    }
}
