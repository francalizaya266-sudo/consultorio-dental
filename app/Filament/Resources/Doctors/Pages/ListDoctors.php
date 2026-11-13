<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Schemas\DoctorForm;
use App\Models\Doctor;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDoctors extends ListRecords
{
    use HasNoBreadcrumbs;

    protected static string $resource = DoctorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo doctor'),
        ];
    }

    /**
     * La pregunta al apagar "Activo" en la lista: qué deja de pasar y cuántas citas le quedan por atender.
     * Esas citas siguen en la agenda; lo que se corta son las citas nuevas.
     */
    public function deactivateAction(): Action
    {
        $doctor = fn (array $arguments): ?Doctor => Doctor::find($arguments['doctor'] ?? null);

        return Action::make('deactivate')
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading(fn (array $arguments) => '¿Desactivar a '.$doctor($arguments)?->name.'?')
            ->modalDescription(fn (array $arguments) => ($found = $doctor($arguments)) ? DoctorForm::deactivationWarning($found) : null)
            ->modalSubmitActionLabel('Desactivar')
            ->action(function (array $arguments) use ($doctor): void {
                $found = $doctor($arguments);

                if ($found === null || ! DoctorResource::canEdit($found)) {
                    return;
                }

                $found->update(['is_active' => false]);

                Notification::make()->success()->title($found->name.' ya no atiende')->body('Sus citas pendientes siguen en la agenda.')->send();
            });
    }
}
