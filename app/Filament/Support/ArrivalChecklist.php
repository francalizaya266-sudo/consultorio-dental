<?php

namespace App\Filament\Support;

use App\Enums\PatientRelationship;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\ConsentService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

/**
 * Lo que conviene resolver con el paciente cuando llega, mientras espera: los datos que le faltan, sus
 * antecedentes sin llenar, lo que debe de antes y si su trabajo todavía no llegó del laboratorio. Sale en el
 * aviso de "Paciente en sala", con el botón para completar su ficha.
 */
final class ArrivalChecklist
{
    /**
     * @return list<string> Una frase por cosa pendiente; vacío si no falta nada.
     */
    public static function for(Patient $patient, User $user): array
    {
        $missing = array_keys(array_filter([
            'DNI' => blank($patient->document_number),
            'fecha de nacimiento' => $patient->birth_date === null,
            'celular' => $patient->account === null,
            'apoderado' => $patient->isMinor() && app(ConsentService::class)->defaultSigner($patient)['signer_relationship'] === PatientRelationship::Self,
            'cómo nos encontró' => $patient->origin === null,
        ]));

        $balance = $user->can('viewAny', Charge::class) ? $patient->balance() : 0.0;
        $inLab = LabOrder::query()->where('patient_id', $patient->getKey())->pending()->orderBy('due_on')->first();

        return array_values(array_filter([
            $missing !== [] ? 'Le falta: '.Arr::join($missing, ', ', ' y ').'.' : null,
            $patient->medicalBackground === null ? 'No ha llenado sus antecedentes médicos: puede hacerlo en su celular, desde Mi cuenta.' : null,
            $balance > 0 ? 'Debe '.Money::format($balance).' de antes.' : null,
            $inLab ? '"'.$inLab->description.'" todavía no llega del laboratorio ('.($inLab->due_on->isPast() && ! $inLab->due_on->isToday() ? 'debía llegar' : 'llega').' el '.$inLab->due_on->translatedFormat('D j M').').' : null,
        ]));
    }

    /**
     * Agrega al aviso de llegada lo pendiente del paciente; si hay algo, el aviso se queda hasta que lo cierren.
     *
     * @param  string|null  $headline  Primera línea del aviso; si no se da, el nombre del paciente.
     */
    public static function addTo(Notification $notification, Appointment $appointment, User $user, ?string $headline = null): Notification
    {
        $patient = Patient::query()->with(['account', 'medicalBackground'])->find($appointment->patient_id);
        $pending = $patient ? self::for($patient, $user) : [];

        if ($pending === []) {
            return $headline ? $notification->body($headline) : $notification;
        }

        return $notification
            ->body(implode('<br>', [$headline ?? $patient->full_name, ...$pending]))
            ->actions([
                Action::make('patient')
                    ->label($user->can('update', $patient) ? 'Completar su ficha' : 'Ver su ficha')
                    ->icon(Heroicon::OutlinedUser)
                    ->url(PatientResource::getUrl($user->can('update', $patient) ? 'edit' : 'view', ['record' => $patient]))
                    ->button(),
            ])
            ->persistent();
    }
}
