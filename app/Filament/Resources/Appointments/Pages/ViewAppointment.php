<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Actions\AppointmentActions;
use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewAppointment extends ViewRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...AppointmentActions::all(),
            Action::make('notes')
                ->label('Editar notas')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->authorize('update')
                ->fillForm(fn () => $this->record->only(['internal_notes', 'fixed_date']))
                ->schema([
                    Toggle::make('fixed_date')->label('Fecha fija (el paciente no la mueve solo)'),
                    Textarea::make('internal_notes')->label('Notas internas')->rows(3),
                ])
                ->action(fn (array $data) => $this->record->update($data)),
        ];
    }
}
