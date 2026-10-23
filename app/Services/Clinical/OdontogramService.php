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
use Illuminate\Support\Facades\DB;

/** Odontograma por consulta: se parte del último y cada toque marca o desmarca un hallazgo. */
final class OdontogramService
{
    /** Lo que queda al tratar un hallazgo que cambia: la caries pasa a restauración; la extracción, a pieza ausente. */
    public const TREATED_AS = ['caries' => 'restauracion', 'extraccion_indicada' => 'ausente', 'remanente_radicular' => 'ausente'];

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

    /** Si una marca roja se puede pasar a tratada de un toque. */
    public static function canMarkTreated(OdontogramEntry $entry): bool
    {
        return $entry->state === ToothState::Bad
            && (array_key_exists($entry->condition->code, self::TREATED_AS) || $entry->condition->allows_good_state);
    }

    /**
     * Pasa una marca roja a tratada: la misma en azul o, si el tratamiento la cambia, la nueva.
     * La caries queda como restauración en esa superficie; la pieza extraída queda ausente y sin sus otras marcas.
     */
    public function markTreated(OdontogramEntry $entry, ?string $abbreviation = null): OdontogramEntry
    {
        $entry->loadMissing(['condition', 'odontogram']);

        if (! self::canMarkTreated($entry)) {
            throw ClinicalException::cannotMarkTreated();
        }

        $code = self::TREATED_AS[$entry->condition->code] ?? null;

        if ($code === null) {
            $entry->update(['state' => ToothState::Good]);

            return $entry;
        }

        $condition = OdontogramCondition::query()->where('code', $code)->firstOrFail();
        $odontogram = $entry->odontogram;

        return DB::transaction(function () use ($entry, $condition, $odontogram, $abbreviation) {
            $replaced = $condition->applies_to === 'tooth'
                ? $odontogram->entries()->where('tooth', $entry->tooth)->whereNull('to_tooth')->get()
                : collect([$entry]);
            $replaced->each->delete();

            $treated = $odontogram->entries()->firstOrNew([
                'odontogram_condition_id' => $condition->getKey(),
                'tooth' => $entry->tooth,
                'surface' => $condition->applies_to === 'surface' ? $entry->surface : null,
                'to_tooth' => null,
            ]);
            $treated->state = ToothState::Good;

            if (in_array($abbreviation, $condition->abbreviations ?? [], true)) {
                $treated->abbreviation = $abbreviation;
            }

            $treated->save();

            return $treated;
        });
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
