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
 * Odontograma interactivo. Se toca una pieza y se abre su ficha (sus caras en grande, lo que tiene y lo que
 * se le puede marcar); o se elige un hallazgo (el "pincel") y se toca pieza tras pieza. También se escribe o
 * se dicta: «36 MO caries, 18 ausente». Rojo = por tratar, azul = tratado. En modo lectura permite ver
 * versiones anteriores y qué cambió en cada una.
 *
 * El pincel, la pieza abierta, los filtros y la pantalla completa viven en el navegador
 * (public/js/odontogram.js) y el dibujo se pinta apenas se toca: al servidor solo va cada marca. Debajo, lo
 * que está en rojo se sugiere para el diagnóstico y el plan.
 */
class OdontogramChart extends Component
{
    /** Hallazgos que se muestran como botones; el resto está en "Otros". */
    public const FREQUENT = ['caries', 'restauracion', 'sellante', 'extraccion_indicada', 'ausente', 'corona', 'tratamiento_pulpar', 'implante', 'fractura', 'remanente_radicular', 'protesis_fija', 'ortodoncia_fija'];

    /** Lo que la ficha de la pieza ofrece marcar primero, según el tipo de diente; el resto se busca. */
    public const QUICK = [
        'posterior' => ['caries', 'restauracion', 'sellante', 'corona', 'tratamiento_pulpar', 'extraccion_indicada', 'ausente', 'fractura', 'remanente_radicular', 'implante'],
        'anterior' => ['caries', 'restauracion', 'corona', 'tratamiento_pulpar', 'fractura', 'extraccion_indicada', 'ausente', 'remanente_radicular', 'implante', 'giroversion'],
    ];

    /** Nombres cortos para los botones (el nombre completo queda en el título y en la lista). */
    public const CHIP_NAMES = [
        'restauracion' => 'Restauración', 'extraccion_indicada' => 'Extracción', 'ausente' => 'Ausente',
        'corona' => 'Corona', 'implante' => 'Implante', 'ortodoncia_fija' => 'Ortodoncia fija',
    ];

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
        unset($this->odontogram, $this->newEntryIds);
    }

    /** Toque con el pincel en una pieza o superficie: marca el hallazgo (en un puente, de $tooth a $toTooth). */
    public function mark(int $tooth, ?string $surface = null, ?string $code = null, string $state = 'bad', ?string $abbreviation = null, ?int $toTooth = null): void
    {
        $condition = $code ? $this->conditions->firstWhere('code', $code) : null;

        if (! $condition || ! $this->canEdit()) {
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
     * De la lista o de la ficha: la caries pasa a restauración, la extracción a pieza ausente y lo demás a azul
     * (una marca o todas las de una fila, como las caras de una misma caries).
     * Si con eso queda hecho un procedimiento del plan, la atención lo marca como trabajado hoy.
     *
     * @param  int|list<int>  $entryIds
     */
    public function markTreated(int|array $entryIds, ?string $abbreviation = null): void
    {
        $entries = $this->canEdit() ? $this->odontogram->entries->whereIn('id', (array) $entryIds) : collect();
        $finished = collect();

        foreach ($entries as $entry) {
            [$tooth, $code, $was] = [$entry->tooth, $entry->condition->code, $entry->abbreviation];
            $this->attempt(fn () => app(OdontogramService::class)->markTreated($entry, $abbreviation));

            $finished = $finished->merge(app(OdontogramSuggestions::class)->finishedItems($this->odontogram->consultation, $tooth, $code, $was));
        }

        if ($finished->isNotEmpty()) {
            $this->dispatch('odontogram-plan-items-worked', items: $finished->pluck('id')->unique()->values()->all());
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
        unset($this->odontogram, $this->dictated, $this->newEntryIds);

        if ($marked) {
            Notification::make()->success()->title($marked === 1 ? 'Se marcó 1 hallazgo.' : "Se marcaron {$marked} hallazgos.")->send();
        }
    }

    /** @param  int|list<int>  $entryIds */
    public function remove(int|array $entryIds): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $this->odontogram->entries()->whereKey((array) $entryIds)->get()->each->delete();
        unset($this->odontogram, $this->newEntryIds);
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

    /**
     * Las marcas nuevas en esta versión: las que no estaban igual en la versión anterior del paciente (en la
     * atención de hoy, lo marcado hoy). En el primer odontograma, todas.
     *
     * @return list<int>
     */
    #[Computed]
    public function newEntryIds(): array
    {
        $odontogram = $this->odontogram;

        if (! $odontogram) {
            return [];
        }

        $previous = Odontogram::query()
            ->where('patient_id', $odontogram->patient_id)
            ->where('id', '<', $odontogram->getKey())
            ->latest('id')
            ->first()?->entries()->get()
            ->map(self::signature(...))
            ->flip() ?? collect();

        return $odontogram->entries
            ->reject(fn (OdontogramEntry $entry) => $previous->has(self::signature($entry)))
            ->modelKeys();
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
        $rows = $odontogram ? Teeth::rows($odontogram->dentition) : [];
        // En el primer odontograma todo es nuevo: resaltarlo no dice nada.
        $newIds = count($this->newEntryIds) < $entries->count() ? $this->newEntryIds : [];
        $teeth = self::teethData($entries, $newIds, $rows);

        return view('livewire.odontogram-chart', [
            'odontogram' => $odontogram,
            'rows' => $rows,
            'teeth' => $teeth,
            'spans' => $entries->filter(fn (OdontogramEntry $e) => $e->to_tooth !== null)->values(),
            'today' => $newIds,
            'groups' => self::groups($entries, $newIds),
            'summary' => collect(['bad', 'good', 'missing', 'today'])->mapWithKeys(fn (string $flag) => [$flag => collect($teeth)->filter(fn (array $tooth) => $tooth['flags'][$flag])->count()])->all(),
            'state' => $odontogram ? ['rows' => $rows, 'teeth' => $this->inspectorData($entries, $newIds, $rows)] : [],
            'preview' => collect($this->dictated)->pluck('marks')->flatten(1)->flatMap(fn (array $mark) => self::spanTeeth($mark['tooth'], $mark['toTooth'], $rows))->unique()->values()->all(),
            'frequent' => $this->conditions->whereIn('code', self::FREQUENT)->sortBy(fn ($c) => array_search($c->code, self::FREQUENT))->values(),
            'canEdit' => $canEdit,
            'config' => [
                'editable' => $canEdit,
                'catalog' => $canEdit ? $this->catalog() : [],
                'frequent' => self::FREQUENT,
                'favorites' => $canEdit ? $this->favorites->pluck('code')->all() : [],
                'quick' => self::QUICK,
                'surfaceNames' => collect(['V', 'P', 'L', 'M', 'D', 'O', 'I'])->mapWithKeys(fn (string $s) => [$s => Teeth::surfaceName($s)])->all(),
            ],
            'suggestions' => $canEdit ? $this->suggestions() : [],
        ]);
    }

    /**
     * Lo que la ficha de cada pieza muestra sin preguntar al servidor: su nombre y lo que tiene, con las caras de
     * una misma caries juntas (como en la lista). Un puente aparece en cada pieza que cubre.
     *
     * @param  Collection<int, OdontogramEntry>  $entries
     * @param  list<int>  $newIds
     * @param  list<list<int>>  $rows
     * @return array<int, array{name: string, posterior: bool, entries: list<array{ids: list<int>, code: string, name: string, state: string, where: string, treatable: bool, new: bool}>}>
     */
    private function inspectorData(Collection $entries, array $newIds, array $rows): array
    {
        $byTooth = [];

        foreach (self::groups($entries, $newIds) as $group) {
            $first = $entries->firstWhere('id', $group['ids'][0]);

            foreach (self::spanTeeth($first->tooth, $first->to_tooth, $rows) as $tooth) {
                $byTooth[$tooth][] = [
                    'ids' => $group['ids'],
                    'code' => $group['code'],
                    'name' => $group['name'],
                    'state' => $group['state'],
                    'where' => match (true) {
                        $first->to_tooth !== null => "de la {$first->tooth} a la {$first->to_tooth}",
                        $group['surfaces'] !== null => $group['surfaces'],
                        default => 'toda la pieza',
                    },
                    'treatable' => $group['treatable'],
                    'new' => $group['new'],
                ];
            }
        }

        return collect(array_merge(...$rows))->mapWithKeys(fn (int $tooth) => [$tooth => [
            'name' => Teeth::name($tooth),
            'posterior' => ! Teeth::isAnterior($tooth),
            'entries' => $byTooth[$tooth] ?? [],
        ]])->all();
    }

    /**
     * Lo que el navegador necesita de cada hallazgo para elegirlo y pintar sin preguntar al servidor.
     *
     * @return array<string, array{name: string, short: string, appliesTo: string, good: bool, bad: bool, abbreviations: list<string>, meanings: array<string, array{0: string, 1: string}>, treatedAs: string}>
     */
    private function catalog(): array
    {
        return $this->conditions->mapWithKeys(fn (OdontogramCondition $condition) => [$condition->code => [
            'name' => $condition->name,
            'short' => self::CHIP_NAMES[$condition->code] ?? $condition->name,
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
     * Por pieza: color de cada superficie, hallazgos de pieza completa, siglas y lo que la pieza tiene (algo por
     * tratar, algo tratado, ausente, algo nuevo) para los filtros. Un puente cuenta en cada pieza que cubre.
     *
     * @param  Collection<int, OdontogramEntry>  $entries
     * @param  list<int>  $newIds  Marcas nuevas en esta versión.
     * @param  list<list<int>>  $rows  Las filas del dibujo, para saber qué piezas cubre un puente.
     * @return array<int, array{surfaces: array<string, string>, marks: array<string, string>, labels: list<array{text: string, state: string, meaning: string}>, flags: array{bad: bool, good: bool, missing: bool, today: bool}}>
     */
    public static function teethData(Collection $entries, array $newIds = [], array $rows = []): array
    {
        $teeth = [];
        $empty = ['surfaces' => [], 'marks' => [], 'labels' => [], 'flags' => ['bad' => false, 'good' => false, 'missing' => false, 'today' => false]];

        foreach ($rows ? array_merge(...$rows) : [] as $tooth) {
            $teeth[$tooth] = $empty;
        }

        foreach ($entries->sortBy('id') as $entry) {
            $data = $teeth[$entry->tooth] ?? $empty;
            $state = $entry->state->value;

            if ($entry->surface) {
                $data['surfaces'][$entry->surface] = $state;
            } elseif ($entry->to_tooth === null) {
                $data['marks'][$entry->condition->code] = $state;
            }

            // Una sigla por recuadro aunque se repita en varias caras (CD en mesial y en oclusal).
            $text = $entry->to_tooth === null ? self::label($entry) : '';
            $repeated = collect($data['labels'])->contains(fn (array $label) => $label['text'] === $text && $label['state'] === $state);

            if ($text !== '' && ! $repeated) {
                $data['labels'][] = ['text' => $text, 'state' => $state, 'meaning' => OdontogramAbbreviations::full($entry->condition->code, $entry->abbreviation) ?? $entry->condition->name];
            }

            $teeth[$entry->tooth] = $data;

            foreach (self::spanTeeth($entry->tooth, $entry->to_tooth, $rows) as $tooth) {
                $flags = ($teeth[$tooth] ?? $empty)['flags'];
                $missing = in_array($entry->condition->code, ['ausente', 'edentulo_total'], true);
                $teeth[$tooth] = ($teeth[$tooth] ?? $empty);
                $teeth[$tooth]['flags'] = [
                    'bad' => $flags['bad'] || $state === 'bad',
                    'good' => $flags['good'] || ($state === 'good' && ! $missing),
                    'missing' => $flags['missing'] || $missing,
                    'today' => $flags['today'] || in_array($entry->id, $newIds, true),
                ];
            }
        }

        return $teeth;
    }

    /**
     * La lista de hallazgos, una fila por hallazgo en cada pieza: las caras de una misma caries van juntas.
     *
     * @param  Collection<int, OdontogramEntry>  $entries
     * @param  list<int>  $newIds
     * @return list<array{ids: list<int>, tooth: int, where: string, surfaces: string|null, name: string, meaning: string|null, state: string, code: string, treatable: bool, new: bool}>
     */
    public static function groups(Collection $entries, array $newIds = []): array
    {
        return $entries
            ->groupBy(fn (OdontogramEntry $e) => implode('|', [$e->tooth, $e->to_tooth, $e->odontogram_condition_id, $e->state->value, $e->abbreviation]))
            ->map(function (Collection $group) use ($newIds) {
                $first = $group->first();
                $surfaces = $group->pluck('surface')->filter()->sortBy(fn (string $s) => array_search($s, ['M', 'O', 'I', 'D', 'V', 'P', 'L'], true))->map(Teeth::surfaceName(...))->values()->all();

                return [
                    'ids' => $group->modelKeys(),
                    'tooth' => $first->tooth,
                    'where' => $first->to_tooth !== null ? "{$first->tooth} a {$first->to_tooth}" : (string) $first->tooth,
                    'surfaces' => $surfaces ? self::sentence($surfaces) : null,
                    'name' => self::findingName($first),
                    'meaning' => OdontogramAbbreviations::full($first->condition->code, $first->abbreviation),
                    'state' => $first->state->value,
                    'code' => $first->condition->code,
                    'treatable' => OdontogramService::canMarkTreated($first),
                    'new' => $group->contains(fn (OdontogramEntry $e) => in_array($e->id, $newIds, true)),
                ];
            })
            ->sortBy([['tooth', 'asc'], ['where', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Las piezas que cubre una marca: la suya o, en un puente, todas las de la fila entre sus dos extremos.
     *
     * @param  list<list<int>>  $rows
     * @return list<int>
     */
    public static function spanTeeth(int $tooth, ?int $toTooth, array $rows): array
    {
        if ($toTooth === null) {
            return [$tooth];
        }

        foreach ($rows as $row) {
            $a = array_search($tooth, $row, true);
            $b = array_search($toTooth, $row, true);

            if ($a !== false && $b !== false) {
                return array_slice($row, min($a, $b), abs($a - $b) + 1);
            }
        }

        return [$tooth];
    }

    /** @param  list<string>  $words */
    private static function sentence(array $words): string
    {
        $last = array_pop($words);

        return $words ? implode(', ', $words).' y '.$last : $last;
    }

    private static function signature(OdontogramEntry $entry): string
    {
        return implode('|', [$entry->odontogram_condition_id, $entry->tooth, $entry->surface, $entry->to_tooth, $entry->state->value, $entry->abbreviation]);
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

        unset($this->odontogram, $this->newEntryIds);
    }
}
