<?php

namespace App\Services\Clinical;

use App\Enums\Dentition;
use App\Enums\OdontogramType;
use App\Enums\ToothState;
use App\Exceptions\ClinicalException;
use App\Models\Consultation;
use App\Models\Odontogram;
use App\Models\OdontogramCondition;
use App\Models\OdontogramEntry;
use App\Support\Teeth;

/** Odontograma por consulta: se parte del último y cada toque marca o desmarca un hallazgo. */
final class OdontogramService
{
    /** Odontograma de la consulta: copia del último del paciente o, si es el primero, uno inicial según su edad. */
    public function forConsultation(Consultation $consultation): Odontogram
    {
        if ($consultation->odontogram) {
            return $consultation->odontogram;
        }

        $latest = $consultation->patient->odontograms()->with('entries')->latest('id')->first();

        $odontogram = $latest
            ? $latest->copyForConsultation($consultation)
            : Odontogram::create([
                'patient_id' => $consultation->patient_id,
                'consultation_id' => $consultation->getKey(),
                'doctor_id' => $consultation->doctor_id,
                'type' => OdontogramType::Initial,
                'dentition' => $consultation->patient->suggestedDentition(),
            ]);

        $consultation->setRelation('odontogram', $odontogram);

        return $odontogram;
    }

    /**
     * Marca un hallazgo. Si la misma marca ya estaba, la quita: tocar dos veces deshace.
     *
     * @return OdontogramEntry|null La marca creada o cambiada; null si se quitó.
     */
    public function toggle(
        Odontogram $odontogram,
        OdontogramCondition $condition,
        ToothState $state,
        int $tooth,
        ?string $surface = null,
        ?int $toTooth = null,
        ?string $abbreviation = null,
    ): ?OdontogramEntry {
        $this->assertTooth($odontogram, $tooth);

        [$surface, $toTooth] = match ($condition->applies_to) {
            'surface' => [$this->surfaceFor($tooth, $surface), null],
            'span' => [null, $this->spanEnd($odontogram, $tooth, $toTooth)],
            default => [null, null],
        };

        $state = $this->allowedState($condition, $state);
        $abbreviation = in_array($abbreviation, $condition->abbreviations ?? [], true) ? $abbreviation : null;

        $existing = $odontogram->entries()
            ->where('odontogram_condition_id', $condition->getKey())
            ->where('tooth', $tooth)
            ->where('surface', $surface)
            ->where('to_tooth', $toTooth)
            ->first();

        if ($existing && $existing->state === $state && $existing->abbreviation === $abbreviation) {
            $existing->delete();

            return null;
        }

        if ($existing) {
            $existing->update(['state' => $state, 'abbreviation' => $abbreviation]);

            return $existing;
        }

        return $odontogram->entries()->create([
            'odontogram_condition_id' => $condition->getKey(),
            'tooth' => $tooth,
            'surface' => $surface,
            'to_tooth' => $toTooth,
            'state' => $state,
            'abbreviation' => $abbreviation,
        ]);
    }

    public function changeDentition(Odontogram $odontogram, Dentition $dentition): void
    {
        $teeth = Teeth::all($dentition);
        $outside = $odontogram->entries()
            ->where(fn ($query) => $query->whereNotIn('tooth', $teeth)->orWhere(fn ($span) => $span->whereNotNull('to_tooth')->whereNotIn('to_tooth', $teeth)))
            ->exists();

        if ($outside) {
            throw ClinicalException::teethOutsideDentition();
        }

        $odontogram->update(['dentition' => $dentition]);
    }

    /** Si el hallazgo solo existe en un color (ej. ausente = azul), se usa ese. */
    private function allowedState(OdontogramCondition $condition, ToothState $state): ToothState
    {
        return match (true) {
            $state === ToothState::Bad && ! $condition->allows_bad_state => ToothState::Good,
            $state === ToothState::Good && ! $condition->allows_good_state => ToothState::Bad,
            default => $state,
        };
    }

    private function assertTooth(Odontogram $odontogram, int $tooth): void
    {
        if (! Teeth::exists($tooth, $odontogram->dentition)) {
            throw ClinicalException::unknownTooth($tooth);
        }
    }

    private function surfaceFor(int $tooth, ?string $surface): string
    {
        if (! in_array($surface, Teeth::surfaces($tooth), true)) {
            throw ClinicalException::needsSurface();
        }

        return $surface;
    }

    private function spanEnd(Odontogram $odontogram, int $tooth, ?int $toTooth): int
    {
        if ($toTooth === null) {
            throw ClinicalException::needsSpan();
        }

        $this->assertTooth($odontogram, $toTooth);

        $sameRow = collect(Teeth::rows($odontogram->dentition))
            ->contains(fn (array $row) => in_array($tooth, $row, true) && in_array($toTooth, $row, true));

        if (! $sameRow) {
            throw ClinicalException::spanAcrossArches();
        }

        return $toTooth;
    }
}
