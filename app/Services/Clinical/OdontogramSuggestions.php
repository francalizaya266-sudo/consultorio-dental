<?php

namespace App\Services\Clinical;

use App\Enums\PlanItemStatus;
use App\Enums\ToothState;
use App\Exceptions\ClinicalException;
use App\Livewire\OdontogramChart;
use App\Models\Consultation;
use App\Models\Icd10Code;
use App\Models\Odontogram;
use App\Models\OdontogramEntry;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Del odontograma al diagnóstico y al plan: cada hallazgo en rojo, según su sigla, propone un
 * diagnóstico CIE-10 y un tratamiento del catálogo con su precio. Nada entra solo: el doctor
 * elige qué se agrega, porque el plan lleva precios y lo trabajado se cobra al terminar.
 */
final class OdontogramSuggestions
{
    /** Hallazgo en rojo (o "hallazgo:sigla") → pares [diagnóstico CIE-10, tratamiento por el slug del catálogo]. */
    public const RULES = [
        'caries' => [['K02.1', 'curacion-con-resina']],
        'caries:MB' => [['K02.0', null]],
        'caries:CE' => [['K02.0', 'curacion-con-resina']],
        'caries:CDP' => [['K02.1', 'curacion-con-resina'], ['K04.0', 'endodoncia']],
        'restauracion' => [[null, 'curacion-con-resina']],
        'restauracion_temporal' => [[null, 'curacion-con-resina']],
        'defecto_esmalte' => [['K00.4', 'curacion-con-resina']],
        'fractura' => [['S02.5', 'curacion-con-resina']],
        'extraccion_indicada' => [[null, 'extraccion-simple']],
        'remanente_radicular' => [['K08.3', 'extraccion-simple']],
        'tratamiento_pulpar' => [[null, 'endodoncia']],
        'impactacion' => [['K01.1', null]],
        'superficie_desgastada' => [['K03.0', null]],
        'posicion' => [['K07.3', null]],
        'giroversion' => [['K07.3', null]],
        'migracion' => [['K07.3', null]],
    ];

    /** Se ofrecen sin marcar, en el color que tengan: la pieza ausente se puede reponer con un implante. */
    public const OPTIONAL = [
        'ausente' => [['K08.1', 'implante']],
    ];

    public function __construct(private readonly TreatmentPlanService $plans) {}

    /**
     * Una sugerencia por pieza y tratamiento (con sus superficies juntas), o por pieza y diagnóstico
     * cuando no hay tratamiento en el catálogo. Dice qué ya está en un plan vigente o diagnosticado hoy.
     *
     * @return list<array{key: string, tooth: string, surfaces: string|null, findings: list<string>, diagnoses: Collection<int, Icd10Code>, treatment: Treatment|null, planned: bool, diagnosed: bool, optional: bool, done: bool, suggested: bool}>
     */
    public function rows(?Odontogram $odontogram, ?Consultation $consultation = null): array
    {
        if (! $odontogram) {
            return [];
        }

        $pairs = collect();

        foreach ($odontogram->entries()->with('condition')->whereNull('to_tooth')->orderBy('tooth')->orderBy('id')->get() as $entry) {
            /** @var OdontogramEntry $entry */
            $optional = self::OPTIONAL[$entry->condition->code] ?? null;

            foreach ($optional ?? ($entry->state === ToothState::Bad ? self::rulesFor($entry->condition->code, $entry->abbreviation) : []) as [$diagnosis, $treatment]) {
                $pairs->push(['entry' => $entry, 'diagnosis' => $diagnosis, 'treatment' => $treatment, 'optional' => $optional !== null]);
            }
        }

        if ($pairs->isEmpty()) {
            return [];
        }

        $codes = Icd10Code::query()->whereIn('code', $pairs->pluck('diagnosis')->filter()->unique())->get()->keyBy('code');
        $treatments = Treatment::query()->whereIn('slug', $pairs->pluck('treatment')->filter()->unique())->get()->keyBy('slug');
        $planned = $this->plans->plannedKeys($odontogram->patient_id);
        $diagnosedToday = $consultation
            ? $consultation->diagnoses()->get(['icd10_code_id', 'tooth'])->map(fn ($d) => $d->icd10_code_id.'-'.$d->tooth)->all()
            : [];

        return $pairs
            ->map(fn (array $pair) => [...$pair, 'diagnosis' => $codes[$pair['diagnosis']] ?? null, 'treatment' => $treatments[$pair['treatment']] ?? null])
            ->filter(fn (array $pair) => $pair['diagnosis'] || $pair['treatment'])
            ->groupBy(fn (array $pair) => $pair['entry']->tooth.'-'.($pair['treatment'] ? 't'.$pair['treatment']->getKey() : 'd'.$pair['diagnosis']->getKey()))
            ->map(function (Collection $group, string $key) use ($planned, $diagnosedToday) {
                $first = $group->first();
                $tooth = (string) $first['entry']->tooth;
                $treatment = $first['treatment'];
                $diagnoses = $group->pluck('diagnosis')->filter()->unique('id')->values();
                $isPlanned = $treatment !== null && in_array($treatment->getKey().'-'.$tooth, $planned, true);
                $isDiagnosed = $diagnoses->every(fn (Icd10Code $code) => in_array($code->getKey().'-'.$tooth, $diagnosedToday, true));
                $optional = $group->every(fn (array $pair) => $pair['optional']);
                $done = ($treatment === null || $isPlanned) && $isDiagnosed;

                return [
                    'key' => $key,
                    'tooth' => $tooth,
                    'surfaces' => self::surfaces($group->pluck('entry.surface')),
                    'findings' => $group->map(fn (array $pair) => OdontogramChart::findingName($pair['entry']))->unique()->values()->all(),
                    'diagnoses' => $diagnoses,
                    'treatment' => $treatment,
                    'planned' => $isPlanned,
                    'diagnosed' => $isDiagnosed,
                    'optional' => $optional,
                    'done' => $done,
                    // Marcado de entrada lo nuevo para el plan, o el diagnóstico que no tiene tratamiento.
                    'suggested' => ! $done && ! $optional && ($treatment ? ! $isPlanned : true),
                ];
            })
            // Lo opcional (el implante de una pieza ausente) va al final.
            ->sortBy(fn (array $row) => sprintf('%d-%02d', $row['optional'], $row['tooth']))
            ->values()
            ->all();
    }

    /**
     * Renglones para un plan nuevo con lo que propone el odontograma y todavía no está en un plan vigente.
     *
     * @return list<array<string, mixed>>
     */
    public function planItems(?Odontogram $odontogram): array
    {
        return collect($this->rows($odontogram))
            ->filter(fn (array $row) => $row['treatment'] && ! $row['planned'] && ! $row['optional'])
            ->map(fn (array $row) => $this->planItem($row))
            ->values()
            ->all();
    }

    /**
     * Agrega lo elegido: los procedimientos al borrador del plan (con su precio) y devuelve los
     * diagnósticos que faltan en la atención, para que la pantalla los sume a su lista.
     *
     * @param  list<string>  $keys
     * @return array{plan: TreatmentPlan|null, added: int, total: float, diagnoses: list<array{icd10_code_id: int, tooth: string}>}
     */
    public function apply(Consultation $consultation, User $by, array $keys): array
    {
        if ($by->doctorId() !== $consultation->doctor_id) {
            throw ClinicalException::notAuthor();
        }

        $rows = collect($this->rows($consultation->odontogram, $consultation))->whereIn('key', $keys);

        $items = $rows
            ->filter(fn (array $row) => $row['treatment'] && ! $row['planned'])
            ->map(fn (array $row) => $this->planItem($row))
            ->values()
            ->all();
        [$plan, $added] = $this->plans->addToDraft($consultation, $by, $items);

        $diagnoses = $rows
            ->reject(fn (array $row) => $row['diagnosed'])
            ->flatMap(fn (array $row) => $row['diagnoses']->map(fn (Icd10Code $code) => ['icd10_code_id' => $code->getKey(), 'tooth' => $row['tooth']]))
            ->unique(fn (array $diagnosis) => $diagnosis['icd10_code_id'].'-'.$diagnosis['tooth'])
            ->values()
            ->all();

        return ['plan' => $plan, 'added' => $added, 'total' => array_sum(array_column($items, 'unit_price')), 'diagnoses' => $diagnoses];
    }

    /**
     * Al marcar "Tratado" un hallazgo: los procedimientos del plan que con eso quedan hechos hoy
     * (misma pieza y tratamiento, y sin otra marca roja de la pieza que pida ese tratamiento).
     *
     * @return Collection<int, TreatmentPlanItem>
     */
    public function finishedItems(Consultation $consultation, int $tooth, string $code, ?string $abbreviation): Collection
    {
        $treatments = collect(self::rulesFor($code, $abbreviation))->pluck(1)->filter()->unique();

        if ($treatments->isEmpty()) {
            return collect();
        }

        $stillRed = $consultation->odontogram->entries()->with('condition')
            ->where('tooth', $tooth)->whereNull('to_tooth')->where('state', ToothState::Bad)->get()
            ->flatMap(fn (OdontogramEntry $entry) => collect(self::rulesFor($entry->condition->code, $entry->abbreviation))->pluck(1))
            ->filter()
            ->all();

        $slugs = $treatments->diff($stillRed);

        return $this->plans->workableItems($consultation->patient, $consultation)
            ->load('treatment')
            ->filter(fn (TreatmentPlanItem $item) => $item->tooth === (string) $tooth && in_array($item->status, [PlanItemStatus::Pending, PlanItemStatus::InProgress], true))
            ->filter(fn (TreatmentPlanItem $item) => $item->treatment_id && $slugs->contains($item->treatment?->slug))
            ->values();
    }

    /** @return list<array{0: string|null, 1: string|null}> */
    public static function rulesFor(string $code, ?string $abbreviation): array
    {
        return self::RULES["{$code}:{$abbreviation}"] ?? self::RULES[$code] ?? [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function planItem(array $row): array
    {
        return [...$this->plans->itemFor($row['treatment']), 'tooth' => $row['tooth'], 'surfaces' => $row['surfaces']];
    }

    /** "MOD": las superficies juntas en el orden en que se escriben. */
    private static function surfaces(Collection $surfaces): ?string
    {
        return $surfaces->filter()->unique()
            ->sortBy(fn (string $surface) => array_search($surface, TreatmentPlanService::SURFACE_ORDER, true))
            ->implode('') ?: null;
    }
}
