<?php

namespace App\Livewire;

use App\Enums\Dentition;
use App\Enums\ToothState;
use App\Exceptions\ClinicalException;
use App\Models\Consultation;
use App\Models\Odontogram;
use App\Models\OdontogramCondition;
use App\Models\OdontogramEntry;
use App\Models\Patient;
use App\Services\Clinical\FindingsDictation;
use App\Services\Clinical\OdontogramService;
use App\Services\Clinical\OdontogramSuggestions;
use App\Support\Money;
use App\Support\OdontogramAbbreviations;
use App\Support\Teeth;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Odontograma interactivo: se elige un hallazgo y se toca la superficie o la pieza.
 * Rojo = por tratar, azul = tratado. En modo lectura permite ver versiones anteriores.
 *
 * El hallazgo, el color y la sigla elegidos viven en el navegador, y el dibujo se pinta apenas se
 * toca: al servidor solo va cada marca, con todo lo necesario para guardarla. Debajo, lo que está en
 * rojo se sugiere para el diagnóstico y el plan, y los hallazgos también se pueden escribir o dictar.
 */
class OdontogramChart extends Component
{
    /** Hallazgos que se muestran como botones; el resto está en "Otros". */
    public const FREQUENT = ['caries', 'restauracion', 'sellante', 'extraccion_indicada', 'ausente', 'corona', 'tratamiento_pulpar', 'implante', 'fractura', 'remanente_radicular', 'protesis_fija', 'ortodoncia_fija'];

    /** Siglas para hallazgos que no tienen una propia en el catálogo. */
    public const SHORT = [
        'restauracion_temporal' => 'RT', 'erupcion' => 'ERU', 'extruida' => 'EXT', 'intruida' => 'INT',
        'giroversion' => 'GIR', 'migracion' => 'MIG', 'supernumeraria' => 'SN', 'fusion' => 'FUS',
        'geminacion' => 'GEM', 'fractura' => 'FX', 'protesis_fija' => 'PF', 'protesis_removible' => 'PR',
        'protesis_total' => 'PT', 'ortodoncia_fija' => 'AOF', 'ortodoncia_removible' => 'AOR',
        'diastema' => 'DIA', 'transposicion' => 'TRA', 'edentulo_total' => 'EDT',
    ];

    #[Locked]
    public ?int $odontogramId = null;

    #[Locked]
    public ?int $patientId = null;

    #[Locked]
    public bool $editable = false;

    public ?int $selectedTooth = null;

    public string $specifications = '';

    public string $observations = '';

    /** Hallazgos escritos o dictados que esperan "Agregar". */
    public string $dictation = '';

    /** Sugerencias ya agregadas en esta pantalla, mientras la atención guarda sus diagnósticos. */
    public array $applied = [];

    /** Recibe la atención (odontograma de esa consulta) o el paciente (su último odontograma). */
    public function mount(?Model $record = null, ?int $odontogramId = null, bool $editable = false): void
    {
        $odontogram = match (true) {
            $odontogramId !== null => Odontogram::find($odontogramId),
            $record instanceof Consultation => $record->odontogram,
            $record instanceof Patient => $record->odontograms()->latest('id')->first(),
            default => null,
        };

        $this->patientId = $odontogram?->patient_id ?? ($record instanceof Patient ? $record->getKey() : null);
        abort_if($this->patientId && ! auth()->user()->can('viewClinical', Patient::find($this->patientId)), 403);

        $this->editable = $editable;
        $this->load($odontogram);
    }

    /** En modo lectura: cambia a otra versión del odontograma del paciente. */
    public function showVersion(?int $id): void
    {
        if (! $this->editable) {
            $this->load($id ? Odontogram::where('patient_id', $this->patientId)->find($id) : null);
        }
    }

    private function load(?Odontogram $odontogram): void
    {
        $this->odontogramId = $odontogram?->getKey();
        $this->specifications = (string) $odontogram?->specifications;
        $this->observations = (string) $odontogram?->observations;
        $this->selectedTooth = null;
        unset($this->odontogram);
    }

    /**
     * Toque en una pieza o superficie: marca con el hallazgo elegido (en un puente, de $tooth a $toTooth).
     * Sin hallazgo, o sin permiso para cambiar, muestra lo que tiene la pieza.
     */
    public function mark(int $tooth, ?string $surface = null, ?string $code = null, string $state = 'bad', ?string $abbreviation = null, ?int $toTooth = null): void
    {
        $condition = $code ? $this->conditions->firstWhere('code', $code) : null;

        if (! $condition || ! $this->canEdit()) {
            $this->selectedTooth = $this->selectedTooth === $tooth ? null : $tooth;

            return;
        }

        $this->attempt(fn () => app(OdontogramService::class)->toggle(
            $this->odontogram,
            $condition,
            ToothState::tryFrom($state) ?? ToothState::Bad,
            $tooth,
            $surface,
            $toTooth,
            $abbreviation,
        ));
    }

    /**
     * De la lista: la caries pasa a restauración, la extracción a pieza ausente y lo demás a azul.
     * Si con eso queda hecho un procedimiento del plan, la atención lo marca como trabajado hoy.
     */
    public function markTreated(int $entryId, ?string $abbreviation = null): void
    {
        $entry = $this->canEdit() ? $this->odontogram->entries->firstWhere('id', $entryId) : null;

        if (! $entry) {
            return;
        }

        [$tooth, $code, $was] = [$entry->tooth, $entry->condition->code, $entry->abbreviation];
        $this->attempt(fn () => app(OdontogramService::class)->markTreated($entry, $abbreviation));

        $finished = app(OdontogramSuggestions::class)->finishedItems($this->odontogram->consultation, $tooth, $code, $was);

        if ($finished->isNotEmpty()) {
            $this->dispatch('odontogram-plan-items-worked', items: $finished->modelKeys());
        }
    }

    /**
     * Agrega las sugerencias elegidas: los procedimientos van al plan y los diagnósticos a la atención.
     *
     * @param  list<string>  $keys
     */
    public function applySuggestions(array $keys): void
    {
        if (! $this->canEdit() || $keys === []) {
            return;
        }

        $result = app(OdontogramSuggestions::class)->apply($this->odontogram->consultation, auth()->user(), $keys);
        $this->applied = array_values(array_unique([...$this->applied, ...$keys]));

        $this->dispatch(
            'odontogram-suggestions-applied',
            diagnoses: $result['diagnoses'],
            planned: $result['added'],
            plannedTotal: $result['added'] ? Money::format($result['total']) : '',
        );
    }

    /** Marca lo escrito o dictado que se entendió; lo que no, queda en el cuadro para corregirlo. */
    public function addDictated(?string $text = null): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $this->dictation = $text ?? $this->dictation;
        $odontogram = $this->odontogram;
        $pending = [];
        $marked = 0;

        foreach ($this->dictated as $finding) {
            if ($finding['error']) {
                $pending[] = $finding['text'];

                continue;
            }

            foreach ($finding['marks'] as $mark) {
                $condition = $this->conditions->firstWhere('code', $mark['code']);

                try {
                    app(OdontogramService::class)->place($odontogram, $condition, ToothState::from($mark['state']), $mark['tooth'], $mark['surface'], $mark['toTooth'], $mark['abbreviation']);
                    $marked++;
                } catch (ClinicalException $e) {
                    $pending[] = $finding['text'];
                    Notification::make()->warning()->title("{$finding['text']}: {$e->getMessage()}")->send();
                }
            }
        }

        $this->dictation = implode(', ', array_unique($pending));
        unset($this->odontogram, $this->dictated);

        if ($marked) {
            Notification::make()->success()->title($marked === 1 ? 'Se marcó 1 hallazgo.' : "Se marcaron {$marked} hallazgos.")->send();
        }
    }

    public function remove(int $entryId): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $this->odontogram->entries()->whereKey($entryId)->first()?->delete();
        unset($this->odontogram);
    }

    public function setDentition(string $dentition): void
    {
        if (! $this->canEdit() || ! ($value = Dentition::tryFrom($dentition))) {
            return;
        }

        $this->attempt(fn () => app(OdontogramService::class)->changeDentition($this->odontogram, $value));
    }

    public function updatedSpecifications(): void
    {
        $this->saveNotes();
    }

    public function updatedObservations(): void
    {
        $this->saveNotes();
    }

    #[Computed]
    public function odontogram(): ?Odontogram
    {
        return $this->odontogramId
            ? Odontogram::with(['entries.condition', 'doctor', 'consultation'])->find($this->odontogramId)
            : null;
    }

    /** @return Collection<int, OdontogramCondition> */
    #[Computed]
    public function conditions(): Collection
    {
        return OdontogramCondition::query()->where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * Lo que se entendió de lo escrito o dictado, para revisarlo antes de marcar.
     *
     * @return list<array{text: string, summary: string|null, error: string|null, marks: list<array<string, mixed>>}>
     */
    #[Computed]
    public function dictated(): array
    {
        return $this->odontogram && trim($this->dictation) !== ''
            ? app(FindingsDictation::class)->parse($this->dictation, $this->odontogram->dentition, $this->conditions)
            : [];
    }

    /**
     * Otros hallazgos que el doctor marca seguido (en dos pacientes o más): se sugieren junto a los botones.
     *
     * @return Collection<int, OdontogramCondition>
     */
    #[Computed]
    public function favorites(): Collection
    {
        $doctorId = $this->odontogram?->doctor_id;

        if (! $doctorId) {
            return collect();
        }

        $ids = OdontogramEntry::query()
            ->join('odontograms', 'odontograms.id', '=', 'odontogram_entries.odontogram_id')
            ->where('odontograms.doctor_id', $doctorId)
            ->whereIn('odontogram_condition_id', $this->conditions->whereNotIn('code', self::FREQUENT)->modelKeys())
            ->groupBy('odontogram_condition_id')
            ->havingRaw('count(distinct odontograms.patient_id) >= 2')
            ->orderByRaw('count(distinct odontograms.patient_id) desc')
            ->limit(3)
            ->pluck('odontogram_condition_id');

        return $ids->map(fn (int $id) => $this->conditions->firstWhere('id', $id))->filter()->values();
    }

    /** @return Collection<int, Odontogram> */
    #[Computed]
    public function versions(): Collection
    {
        return $this->editable || ! $this->patientId
            ? collect()
            : Odontogram::query()->with('doctor')->where('patient_id', $this->patientId)->latest('id')->get();
    }

    public function canEdit(): bool
    {
        $consultation = $this->odontogram?->consultation;

        return $this->editable && $consultation && auth()->user()->can('update', $consultation);
    }

    public function render(): View
    {
        $odontogram = $this->odontogram;
        $entries = $odontogram?->entries ?? collect();
        $canEdit = $this->canEdit();

        return view('livewire.odontogram-chart', [
            'odontogram' => $odontogram,
            'rows' => $odontogram ? Teeth::rows($odontogram->dentition) : [],
            'teeth' => self::teethData($entries),
            'spans' => $entries->filter(fn (OdontogramEntry $e) => $e->to_tooth !== null)->values(),
            'findings' => $entries
                ->when($this->selectedTooth, fn (Collection $all) => $all->filter(fn (OdontogramEntry $e) => $this->touches($e, $this->selectedTooth)))
                ->sortBy([['tooth', 'asc'], ['id', 'asc']])
                ->values(),
            'frequent' => $this->conditions->whereIn('code', self::FREQUENT)->sortBy(fn ($c) => array_search($c->code, self::FREQUENT))->values(),
            'others' => $this->conditions->whereNotIn('code', self::FREQUENT)->values(),
            'canEdit' => $canEdit,
            'catalog' => $this->catalog(),
            'suggestions' => $canEdit ? $this->suggestions() : [],
            'surfaceNames' => collect(['V', 'P', 'L', 'M', 'D', 'O', 'I'])->mapWithKeys(fn (string $s) => [$s => Teeth::surfaceName($s)])->all(),
        ]);
    }

    /**
     * Lo que el navegador necesita de cada hallazgo para elegirlo y pintar sin preguntar al servidor.
     *
     * @return array<string, array{name: string, appliesTo: string, good: bool, bad: bool, abbreviations: list<string>, meanings: array<string, array{0: string, 1: string}>, treatedAs: string}>
     */
    private function catalog(): array
    {
        return $this->conditions->mapWithKeys(fn (OdontogramCondition $condition) => [$condition->code => [
            'name' => $condition->name,
            'appliesTo' => $condition->applies_to,
            'good' => $condition->allows_good_state,
            'bad' => $condition->allows_bad_state,
            'abbreviations' => $condition->abbreviations ?? [],
            'meanings' => array_intersect_key(OdontogramAbbreviations::MEANINGS[$condition->code] ?? [], array_flip($condition->abbreviations ?? [])),
            'treatedAs' => OdontogramService::TREATED_AS[$condition->code] ?? $condition->code,
        ]])->all();
    }

    /**
     * Sugerencias para el diagnóstico y el plan; lo agregado en esta pantalla se ve como hecho.
     *
     * @return list<array<string, mixed>>
     */
    private function suggestions(): array
    {
        return array_map(
            fn (array $row) => in_array($row['key'], $this->applied, true) ? [...$row, 'done' => true, 'suggested' => false] : $row,
            app(OdontogramSuggestions::class)->rows($this->odontogram, $this->odontogram?->consultation),
        );
    }

    public static function label(OdontogramEntry $entry): string
    {
        return $entry->abbreviation ?? $entry->condition->abbreviation ?? self::SHORT[$entry->condition->code] ?? '';
    }

    /** "Caries (CD · dentina)": el hallazgo con su sigla y lo que significa. */
    public static function findingName(OdontogramEntry $entry): string
    {
        return $entry->condition->name.($entry->abbreviation ? ' ('.OdontogramAbbreviations::withWord($entry->condition->code, $entry->abbreviation).')' : '');
    }

    /**
     * Por pieza: color de cada superficie, hallazgos de pieza completa y siglas.
     *
     * @param  Collection<int, OdontogramEntry>  $entries
     * @return array<int, array{surfaces: array<string, string>, marks: array<string, string>, labels: list<array{text: string, state: string, meaning: string}>}>
     */
    public static function teethData(Collection $entries): array
    {
        $teeth = [];

        foreach ($entries->sortBy('id') as $entry) {
            $data = $teeth[$entry->tooth] ?? ['surfaces' => [], 'marks' => [], 'labels' => []];
            $state = $entry->state->value;

            if ($entry->surface) {
                $data['surfaces'][$entry->surface] = $state;
            } elseif ($entry->to_tooth === null) {
                $data['marks'][$entry->condition->code] = $state;
            }

            if ($entry->to_tooth === null && ($text = self::label($entry)) !== '') {
                $data['labels'][] = ['text' => $text, 'state' => $state, 'meaning' => OdontogramAbbreviations::full($entry->condition->code, $entry->abbreviation) ?? $entry->condition->name];
            }

            $teeth[$entry->tooth] = $data;
        }

        return $teeth;
    }

    private function touches(OdontogramEntry $entry, int $tooth): bool
    {
        if ($entry->to_tooth === null) {
            return $entry->tooth === $tooth;
        }

        $row = collect(Teeth::rows($this->odontogram->dentition))->first(fn (array $r) => in_array($entry->tooth, $r, true)) ?? [];
        [$a, $b] = [array_search($entry->tooth, $row, true), array_search($entry->to_tooth, $row, true)];
        $position = array_search($tooth, $row, true);

        return $position !== false && $position >= min($a, $b) && $position <= max($a, $b);
    }

    private function saveNotes(): void
    {
        if ($this->canEdit()) {
            $this->odontogram->update([
                'specifications' => trim($this->specifications) ?: null,
                'observations' => trim($this->observations) ?: null,
            ]);
        }
    }

    private function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (ClinicalException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();
        }

        unset($this->odontogram);
    }
}
