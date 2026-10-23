<?php

namespace App\Filament\Support;

use App\Filament\Resources\Patients\PatientResource;
use App\Models\MedicalBackground;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Schemas\Components\Callout;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/** Lo que el doctor debe ver antes de atender: alergias, enfermedades, embarazo. */
final class ClinicalAlerts
{
    /** @return list<string> */
    public static function for(Patient $patient): array
    {
        $background = $patient->medicalBackground;

        if (! $background) {
            return [];
        }

        return array_values(array_filter([
            self::meaningful($background->allergies) ? 'Alergia: '.Str::limit($background->allergies, 80) : null,
            ...collect($background->conditions ?? [])->map(fn (string $key) => MedicalBackground::CONDITIONS[$key] ?? null)->all(),
            $background->is_pregnant ? 'Embarazada'.($background->pregnancy_weeks ? " ({$background->pregnancy_weeks} semanas)" : '') : null,
            $background->is_breastfeeding ? 'Dando de lactar' : null,
            self::meaningful($background->medications) ? 'Toma: '.Str::limit($background->medications, 80) : null,
        ]));
    }

    public static function callout(?Patient $patient, bool $withLink = true): ?Callout
    {
        if (! $patient) {
            return null;
        }

        $alerts = self::for($patient);
        $link = $withLink
            ? [Action::make('clinicalRecord')
                ->label('Ver antecedentes')
                ->link()
                ->url(PatientResource::getUrl('clinical', ['record' => $patient]))]
            : [];

        if (! $patient->medicalBackground) {
            return Callout::make('Faltan los antecedentes médicos')
                ->description('Pregunta por alergias, enfermedades y medicamentos y regístralos en la historia.')
                ->warning()
                ->actions($link);
        }

        if ($alerts === []) {
            return Callout::make('Sin alergias ni enfermedades registradas')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->success()
                ->actions($link);
        }

        return Callout::make('Atención: '.implode(' · ', $alerts))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->danger()
            ->actions($link);
    }

    /** "Ninguna", "no", "niega" no cuentan como alerta. */
    private static function meaningful(?string $text): bool
    {
        $text = Str::of((string) $text)->trim()->lower()->ascii()->toString();

        return $text !== '' && ! in_array(rtrim($text, '.'), ['ninguna', 'ninguno', 'no', 'niega', 'no refiere', 'nada', '-'], true);
    }
}
