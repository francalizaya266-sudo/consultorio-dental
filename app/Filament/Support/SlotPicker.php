<?php

namespace App\Filament\Support;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\Slot;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Campos para elegir doctor, día y un horario libre según la agenda inteligente.
 * Recepción puede usar "otra hora" (por ejemplo, fuera del horario), pero el
 * sistema igual impide cruzar dos citas.
 */
final class SlotPicker
{
    /**
     * @param  Appointment|null  $moving  Cita que se mueve: su tratamiento es fijo y su horario no cuenta como ocupado.
     * @return list<Component|Field>
     */
    public static function fields(?Appointment $moving = null): array
    {
        /** @var User $user */
        $user = auth()->user();
        $ownDoctorId = $user->managesAllAgendas() ? null : $user->doctorId();

        return [
            Select::make('doctor_id')
                ->label('Doctor')
                ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                ->default($moving?->doctor_id ?? $ownDoctorId)
                ->disabled($ownDoctorId !== null)
                ->dehydrated()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('slot', null)),

            Select::make('treatment_id')
                ->label('Tratamiento')
                ->options(fn (Get $get) => Treatment::query()
                    ->active()
                    ->when($get('doctor_id'), fn ($q, $doctorId) => $q->whereHas('doctors', fn ($d) => $d->whereKey($doctorId)))
                    ->orderBy('sort_order')
                    ->pluck('name', 'id'))
                ->default($moving?->treatment_id)
                ->hidden($moving !== null)
                ->dehydrated($moving === null)
                ->required($moving === null)
                ->searchable()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('slot', null)),

            DatePicker::make('date')
                ->label('Día')
                ->default(today())
                ->minDate(today())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('slot', null)),

            Select::make('slot')
                ->label('Horario libre')
                ->helperText('★ = pegado a otra cita (no deja huecos).')
                ->options(fn (Get $get) => self::slotOptions($get, $moving))
                ->required(fn (Get $get) => ! $get('manual'))
                ->hidden(fn (Get $get) => (bool) $get('manual'))
                ->placeholder('Elige un horario'),

            Toggle::make('manual')
                ->label('Otra hora (fuera de los horarios libres)')
                ->live(),

            TimePicker::make('manual_time')
                ->label('Hora')
                ->seconds(false)
                ->minutesStep(5)
                ->visible(fn (Get $get) => (bool) $get('manual'))
                ->required(fn (Get $get) => (bool) $get('manual')),
        ];
    }

    /** Fecha y hora elegidas en el formulario. */
    public static function startsAt(array $data): CarbonImmutable
    {
        $time = ($data['manual'] ?? false) ? $data['manual_time'] : $data['slot'];

        return CarbonImmutable::parse($data['date'])->setTimeFromTimeString($time);
    }

    /** @return array<string, string> */
    private static function slotOptions(Get $get, ?Appointment $moving): array
    {
        $doctor = Doctor::find($get('doctor_id'));
        $treatment = Treatment::find($moving?->treatment_id ?? $get('treatment_id'));
        $date = $get('date');

        if (! $doctor || ! $treatment || ! $date) {
            return [];
        }

        $day = CarbonImmutable::parse($date);

        return app(AvailabilityService::class)
            ->slotsFor($doctor, $treatment, $day, earliest: $day->isToday() ? now() : null, ignoreAppointmentId: $moving?->getKey())
            ->mapWithKeys(fn (Slot $slot) => [$slot->label() => $slot->label().($slot->recommended ? ' ★' : '')])
            ->all();
    }
}
