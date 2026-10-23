<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPatient extends ViewRecord
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('book')
                ->label('Nueva cita')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(fn () => AppointmentResource::getUrl('create', ['patient' => $this->getRecord()->getKey()])),
            EditAction::make()
                ->color('gray'),
        ];
    }
}
