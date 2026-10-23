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

    /** Un bloqueo no mueve citas solo: avisa cuáles quedaron dentro para reprogramarlas. */
    public static function warnAboutAppointments(ScheduleBlock $block): void
    {
        $count = Appointment::query()
            ->occupying()
            ->where('starts_at', '<', $block->ends_at)
            ->where('ends_at', '>', $block->starts_at)
            ->when($block->doctor_id, fn (Builder $q, $doctorId) => $q->where('doctor_id', $doctorId))
            ->count();

        if ($count === 0) {
            return;
        }

        Notification::make()
            ->warning()
            ->title($count === 1 ? 'Hay 1 cita en ese horario' : "Hay {$count} citas en ese horario")
            ->body('El bloqueo no las mueve: cámbialas de horario para avisar a los pacientes.')
            ->actions([
                Action::make('see')
                    ->label('Ver citas')
                    ->url(AppointmentResource::getUrl('index', ['tab' => 'upcoming'])),
            ])
            ->persistent()
            ->send();
    }
}
