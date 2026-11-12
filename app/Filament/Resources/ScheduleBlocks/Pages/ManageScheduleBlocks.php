<?php

namespace App\Filament\Resources\ScheduleBlocks\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\ScheduleBlocks\ScheduleBlockResource;
use App\Models\Appointment;
use App\Models\ScheduleBlock;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Builder;

class ManageScheduleBlocks extends ManageRecords
{
    protected static string $resource = ScheduleBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Bloquear horario')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data) => self::forOwnAgenda($data) + ['created_by_user_id' => auth()->id()])
                ->after(fn (ScheduleBlock $record) => self::warnAboutAppointments($record)),
        ];
    }

    /**
     * Un doctor solo bloquea su propia agenda, aunque alguien altere el formulario.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forOwnAgenda(array $data): array
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->managesAllAgendas()) {
            $data['doctor_id'] = $user->doctorId();
        }

        return $data;
    }

    /**
     * Un bloqueo no mueve citas solo: avisa cuáles quedaron dentro, con nombre y hora, y lleva a la lista de
     * esas citas, donde cada una se mueve (y queda listo el aviso al paciente) o se le escribe por WhatsApp.
     */
    public static function warnAboutAppointments(ScheduleBlock $block): void
    {
        $inside = self::appointmentsInside($block)->orderBy('starts_at')->get();

        if ($inside->isEmpty()) {
            return;
        }

        $count = $inside->count();
        $names = $inside->take(3)
            ->map(fn (Appointment $appointment) => $appointment->patient->full_name.' ('.$appointment->starts_at->translatedFormat('D j, H:i').')')
            ->join(', ');

        Notification::make()
            ->warning()
            ->title($count === 1 ? 'Hay 1 cita en ese horario' : "Hay {$count} citas en ese horario")
            ->body($names.($count > 3 ? ' y '.($count - 3).' más' : '').'. El bloqueo no las mueve: cámbialas de horario para avisar a los pacientes.')
            ->actions([
                Action::make('see')
                    ->label($count === 1 ? 'Ver esa cita' : 'Ver esas citas')
                    ->url(self::appointmentsInsideUrl()),
            ])
            ->persistent()
            ->send();
    }

    /**
     * Citas por venir que quedaron dentro del bloqueo (de las agendas que ve quien está en el panel).
     *
     * @return Builder<Appointment>
     */
    public static function appointmentsInside(ScheduleBlock $block): Builder
    {
        return AppointmentResource::getEloquentQuery()
            ->upcoming()
            ->where('starts_at', '<', $block->ends_at)
            ->where('ends_at', '>', $block->starts_at)
            ->when($block->doctor_id, fn (Builder $query, int $doctorId) => $query->where('doctor_id', $doctorId));
    }

    /**
     * Cuántas citas por venir siguen dentro de cada bloqueo, para contarlas en la misma consulta de la tabla.
     *
     * @return Builder<Appointment>
     */
    public static function appointmentsInsideCount(): Builder
    {
        return AppointmentResource::getEloquentQuery()
            ->upcoming()
            ->selectRaw('count(*)')
            ->whereColumn('appointments.starts_at', '<', 'schedule_blocks.ends_at')
            ->whereColumn('appointments.ends_at', '>', 'schedule_blocks.starts_at')
            ->where(fn (Builder $owner) => $owner
                ->whereNull('schedule_blocks.doctor_id')
                ->orWhereColumn('appointments.doctor_id', 'schedule_blocks.doctor_id'));
    }

    /** La lista de citas próximas, solo con las que caen en un bloqueo. */
    public static function appointmentsInsideUrl(): string
    {
        return AppointmentResource::getUrl('index', ['tab' => 'upcoming', 'filters' => ['in_block' => ['isActive' => true]]]);
    }
}
