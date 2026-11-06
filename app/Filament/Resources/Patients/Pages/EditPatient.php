<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Schemas\PatientForm;
use App\Models\Patient;
use App\Services\Patients\PatientRegistrar;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('changePhone')
                ->label('Cambiar celular')
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->color('gray')
                ->modalHeading('Cambiar celular')
                ->modalDescription('Por ejemplo, cuando un hijo ya tiene su propio celular. Su historia y sus citas se mantienen.')
                ->schema([
                    PatientForm::phoneInput()->required()->helperText(null),
                ])
                ->authorize('update')
                ->action(function (array $data, Patient $record) {
                    app(PatientRegistrar::class)->moveToPhone($record, $data['phone']);
                    Notification::make()->success()->title('Celular actualizado')->send();
                    $this->refreshFormData(['relationship']);
                }),
            // Solo un paciente registrado por error: con citas, pagos o historia, el botón explica por qué no.
            DeleteAction::make()
                ->authorizationNotification()
                ->modalDescription('El paciente deja de aparecer en las listas. Se puede recuperar.'),
            RestoreAction::make(),
        ];
    }

    /** Al editar solo van los datos: las citas, los cargos y los pagos ya están en la ficha. */
    protected function getAllRelationManagers(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
