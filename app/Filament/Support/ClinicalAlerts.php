<?php

namespace App\Filament\Support;

use App\Filament\Resources\Patients\PatientResource;
use App\Models\MedicalBackground;
use App\Models\Patient;
use App\Services\Clinical\MedicationCheck;
use Filament\Actions\Action;
use Filament\Schemas\Components\Callout;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Lo que el doctor debe ver antes de atender: alergias, enfermedades, embarazo, medicamentos. Por gravedad:
 * en rojo lo que puede hacer daño en el sillón (alergias, reacción a la anestesia, anticoagulantes o problemas
 * de coagulación); en ámbar lo demás, para tenerlo presente. Lo rojo va primero.
 */
final class ClinicalAlerts
{
    /** Las enfermedades que van en rojo: sangrado y reacción a la anestesia. */
    private const SEVERE_CONDITIONS = ['bleeding_disorder', 'anesthesia_reaction'];

    /** @return list<string> */
    public static function for(Patient $patient): array
    {
        return array_column(self::withSeverity($patient), 'text');
    }

    /** @return list<array{text: string, severe: bool}> */
    public static function withSeverity(Patient $patient): array
    {
        $background = $patient->medicalBackground;

        if (! $background) {
            return [];
        }

        $alerts = array_values(array_filter([
            self::meaningful($background->allergies) ? ['text' => 'Alergia: '.Str::limit($background->allergies, 80), 'severe' => true] : null,
            ...collect($background->conditions ?? [])
                ->map(fn (string $key) => isset(MedicalBackground::CONDITIONS[$key])
                    ? ['text' => MedicalBackground::CONDITIONS[$key], 'severe' => in_array($key, self::SEVERE_CONDITIONS, true)]
                    : null)
                ->all(),
            $background->is_pregnant ? ['text' => 'Embarazada'.($background->pregnancy_weeks ? " ({$background->pregnancy_weeks} semanas)" : ''), 'severe' => false] : null,
            $background->is_breastfeeding ? ['text' => 'Dando de lactar', 'severe' => false] : null,
            self::meaningful($background->medications)
                ? ['text' => 'Toma: '.Str::limit($background->medications, 80), 'severe' => MedicationCheck::mentionsAnticoagulants($background->medications)]
                : null,
        ]));

        // Lo rojo primero, sin cambiar el orden dentro de cada grupo.
        return [...array_filter($alerts, fn (array $alert) => $alert['severe']), ...array_filter($alerts, fn (array $alert) => ! $alert['severe'])];
    }

    /** Si algo de lo que tiene va en rojo. */
    public static function isSevere(Patient $patient): bool
    {
        return in_array(true, array_column(self::withSeverity($patient), 'severe'), true);
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

        $callout = Callout::make('Atención: '.implode(' · ', $alerts))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->actions($link);

        return self::isSevere($patient) ? $callout->danger() : $callout->warning();
    }

    /** "Ninguna", "no", "niega" no cuentan como alerta. */
    /** Lo escrito dice algo: "Ninguna", "No refiere" o "-" no cuentan. */
    public static function meaningful(?string $text): bool
    {
        $text = Str::of((string) $text)->trim()->lower()->ascii()->toString();

        return $text !== '' && ! in_array(rtrim($text, '.'), ['ninguna', 'ninguno', 'no', 'niega', 'no refiere', 'nada', '-'], true);
    }
}
