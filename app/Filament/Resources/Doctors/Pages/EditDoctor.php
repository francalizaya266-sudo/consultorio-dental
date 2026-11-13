<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Schemas\DoctorForm;
use App\Models\Appointment;
use App\Services\Agenda\AvailabilityService;
use App\Support\Dates;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDoctor extends EditRecord
{
    protected static string $resource = DoctorResource::class;

    /** "Guardar cambios" queda a la vista al pie de la pantalla: la ficha es larga y el botón se perdía al medio. */
    public static bool $formActionsAreSticky = true;

    /** @var list<int> */
    protected array $alreadyOutsideSchedule = [];

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DoctorForm::withAccess($data, $this->form->getRawState());
    }

    /** Antes de guardar: las citas que ya estaban fuera del horario (sobrecupos, "otra hora"). De esas no se avisa. */
    protected function beforeSave(): void
    {
        $this->alreadyOutsideSchedule = app(AvailabilityService::class)
            ->outsideSchedule($this->getRecord())
            ->map(fn (Appointment $appointment) => $appointment->getKey())
            ->all();
    }

    /** Si el horario nuevo deja citas ya dadas fuera (se quitó un día o se acortó un tramo), se avisa cuáles. */
    protected function afterSave(): void
    {
        $left = app(AvailabilityService::class)
            ->outsideSchedule($this->getRecord())
            ->reject(fn (Appointment $appointment) => in_array($appointment->getKey(), $this->alreadyOutsideSchedule, true))
            ->values();

        if ($left->isEmpty()) {
            return;
        }

        /** @var Appointment $first */
        $first = $left->first();
        $first->loadMissing('patient');

        Notification::make()
            ->warning()
            ->persistent()
            ->title($left->count() === 1 ? '1 cita ya dada queda fuera del horario nuevo' : $left->count().' citas ya dadas quedan fuera del horario nuevo')
            ->body('La más próxima: '.$first->patient->full_name.', '.Dates::dayAndTime($first->starts_at).'. Siguen agendadas: cámbialas de hora o deja el horario como estaba.')
            ->actions([
                Action::make('seeInAgenda')
                    ->label('Ver en la agenda')
                    ->url(Agenda::getUrl(['date' => $first->starts_at->toDateString(), 'doctor' => $this->getRecord()->getKey()])),
            ])
            ->send();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Doctor guardado';
    }

    protected function getHeaderActions(): array
    {
        return [
            // En el menú ⋮, no como botón rojo. Con citas o historias no se elimina: explica que se apaga "Activo".
            ActionGroup::make([
                DeleteAction::make()
                    ->authorizationNotification()
                    ->modalDescription('Solo se borra a un doctor que nunca tuvo citas ni historias. A los demás se les apaga "Activo". Se puede restaurar.')
                    ->successNotificationTitle('Doctor borrado'),
                RestoreAction::make()
                    ->successNotificationTitle('Doctor restaurado'),
            ]),
        ];
    }
}
