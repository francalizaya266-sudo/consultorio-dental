<?php

namespace App\Filament\Actions;

use App\Filament\Resources\LabOrders\LabOrderResource;
use App\Models\LabOrder;
use App\Models\Patient;
use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Desde la atención, el doctor anota lo que manda al laboratorio (la corona, la prótesis) sin salir de ella:
 * el paciente y quien lo pidió ya se saben. Queda en Laboratorio, que avisa si se atrasa o si la cita queda antes.
 */
final class SendToLabAction
{
    /**
     * @param  Closure(): Patient  $patient
     * @param  Closure(): ?int  $doctorId  El doctor de la atención.
     */
    public static function make(Closure $patient, Closure $doctorId): Action
    {
        return Action::make('sendToLab')
            ->label('Laboratorio')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->visible(fn () => auth()->user()->can('create', LabOrder::class))
            ->modalHeading(fn () => 'Mandar al laboratorio: '.$patient()->full_name)
            ->modalDescription('Queda en Laboratorio con su fecha de entrega: el sistema avisa si se atrasa o si la cita del paciente queda antes.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Anotar trabajo')
            ->schema(fn () => [
                Grid::make(2)->schema(LabOrderResource::workFields($patient()->getKey(), $doctorId())),
            ])
            ->action(function (array $data) use ($patient) {
                $order = LabOrder::create([...$data, 'patient_id' => $patient()->getKey(), 'created_by_user_id' => auth()->id()]);

                LabOrderResource::createdNotification($order)->send();
            });
    }
}
