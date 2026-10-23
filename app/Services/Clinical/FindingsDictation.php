<?php

namespace App\Services\Clinical;

use App\Enums\Dentition;
use App\Models\OdontogramCondition;
use App\Support\OdontogramAbbreviations;
use App\Support\Teeth;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Hallazgos escritos o dictados con el micrófono del teclado, como "36 O CD, 46 MO resina,
 * 18 ausente, puente de 14 a 16". Se entienden con reglas fijas, sin IA ni servicios de afuera,
 * y se muestran antes de marcarlos porque el dictado se puede equivocar.
 */
final class FindingsDictation
{
    private const UNITS = ['uno' => 1, 'un' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8];

    private const TEENS = [
        'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15, 'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18,
        'veintiuno' => 21, 'veintiun' => 21, 'veintidos' => 22, 'veintitres' => 23, 'veinticuatro' => 24, 'veinticinco' => 25,
        'veintiseis' => 26, 'veintisiete' => 27, 'veintiocho' => 28,
    ];

    private const TENS = ['treinta' => 30, 'cuarenta' => 40, 'cincuenta' => 50, 'sesenta' => 60, 'setenta' => 70, 'ochenta' => 80];

    private const SURFACE_WORDS = [
        'oclusal' => 'O', 'ocluso' => 'O', 'mesial' => 'M', 'mesio' => 'M', 'distal' => 'D', 'disto' => 'D',
        'vestibular' => 'V', 'vestibulo' => 'V', 'bucal' => 'V', 'palatino' => 'P', 'palatina' => 'P', 'palatal' => 'P',
        'palato' => 'P', 'lingual' => 'L', 'linguo' => 'L', 'incisal' => 'I', 'inciso' => 'I',
    ];

    private const GOOD_WORDS = ['tratado', 'tratada', 'azul', 'bien', 'buen', 'buena', 'bueno', 'sano', 'sana'];

    private const BAD_WORDS = ['tratar', 'rojo', 'roja', 'mal', 'mala', 'malo', 'desadaptada', 'desadaptado', 'filtrada', 'filtrado'];

    private const STOP_WORDS = ['a', 'al', 'hasta', 'de', 'del', 'la', 'las', 'el', 'los', 'en', 'pieza', 'piezas', 'diente', 'cara', 'caras', 'superficie', 'y', 'con', 'por', 'estado', 'es', 'esta', 'tiene', 'hay'];

    /** Cómo se dice en el consultorio → [hallazgo, sigla]. Los nombres del catálogo y los significados de las siglas también valen. */
    private const PHRASES = [
        'caries' => ['caries', null], 'cariada' => ['caries', null], 'cariado' => ['caries', null],
        'compromiso pulpar' => ['caries', 'CDP'],
        'restauracion temporal' => ['restauracion_temporal', null], 'obturacion temporal' => ['restauracion_temporal', null],
        'restauracion provisional' => ['restauracion_temporal', null],
        'restauracion' => ['restauracion', null], 'restaurada' => ['restauracion', null], 'obturacion' => ['restauracion', null], 'obturada' => ['restauracion', null],
        'sellante' => ['sellante', null], 'sellada' => ['sellante', null],
        'extraccion' => ['extraccion_indicada', null], 'exodoncia' => ['extraccion_indicada', null], 'extraer' => ['extraccion_indicada', null],
        'ausente' => ['ausente', null], 'falta' => ['ausente', null], 'perdida' => ['ausente', null],
        'corona temporal' => ['corona_temporal', 'CT'], 'corona provisional' => ['corona_temporal', 'CT'], 'corona' => ['corona', null],
        'endodoncia' => ['tratamiento_pulpar', 'TC'], 'conducto' => ['tratamiento_pulpar', 'TC'], 'tratamiento pulpar' => ['tratamiento_pulpar', null],
        'implante' => ['implante', 'IMP'],
        'fractura' => ['fractura', null], 'fracturada' => ['fractura', null], 'fracturado' => ['fractura', null], 'rota' => ['fractura', null], 'roto' => ['fractura', null],
        'remanente' => ['remanente_radicular', 'RR'], 'resto radicular' => ['remanente_radicular', 'RR'], 'raiz' => ['remanente_radicular', 'RR'],
        'puente' => ['protesis_fija', null], 'brackets' => ['ortodoncia_fija', null], 'ortodoncia' => ['ortodoncia_fija', null],
        'desgaste' => ['superficie_desgastada', 'DES'], 'desgastada' => ['superficie_desgastada', 'DES'],
        'fosas' => ['fosas_fisuras', 'FFP'], 'fisuras' => ['fosas_fisuras', 'FFP'],
        'impactada' => ['impactacion', 'I'], 'movilidad' => ['movilidad', null], 'movil' => ['movilidad', null],
    ];

    /** Palabras de los significados que se confunden con otras: "temporal" sola no dice qué es temporal. */
    private const AMBIGUOUS_WORDS = ['temporal'];

    /**
     * @param  Collection<int, OdontogramCondition>  $conditions  Hallazgos activos del catálogo.
     * @return list<array{text: string, summary: string|null, error: string|null, marks: list<array{tooth: int, surface: string|null, toTooth: int|null, code: string, state: string, abbreviation: string|null}>}>
     */
    public function parse(string $text, Dentition $dentition, Collection $conditions): array
    {
        $conditions = $conditions->keyBy('code');
        $phrases = $this->phrases($conditions);
        $results = [];
        $previous = null;

        foreach ($this->findings($this->normalize($text)) as $finding) {
            $result = $this->read($finding, $dentition, $conditions, $phrases, $previous);
            $previous = $result['context'] ?? $previous;
            unset($result['context']);
            $results[] = $result;
        }

        return $results;
    }

    /** Minúsculas, sin tildes, con los números dichos en palabras pasados a cifras ("treinta y seis" → 36, "tres seis" → 36). */
    private function normalize(string $text): string
    {
        $text = Str::of($text)->ascii()->lower()->replaceMatches('/[,;.\n\r]+/', ' , ')->replaceMatches('/[^a-z0-9, ]+/', ' ')->squish()->toString();

        $units = implode('|', array_keys(self::UNITS));
        $text = preg_replace_callback(
            '/\b('.implode('|', array_keys(self::TENS)).')(?: y ('.$units.'))?\b/',
            fn (array $m) => (string) (self::TENS[$m[1]] + (isset($m[2]) ? self::UNITS[$m[2]] : 0)),
            $text,
        );
        $text = preg_replace_callback('/\b('.implode('|', array_keys(self::TEENS)).')\b/', fn (array $m) => (string) self::TEENS[$m[1]], $text);
        $text = preg_replace_callback('/\b('.$units.')\b/', fn (array $m) => (string) self::UNITS[$m[1]], $text);

        // Pieza dicha cifra por cifra: "3 6" → 36.
        return preg_replace('/\b([1-8]) ([1-8])\b/', '$1$2', $text);
    }

    /**
     * Separa en hallazgos: por comas o punto, y cada número de pieza empieza uno nuevo
     * (salvo "de 14 a 16", que es un tramo).
     *
     * @return list<array{tooth: int|null, toTooth: int|null, words: list<string>}>
     */
    private function findings(string $text): array
    {
        $findings = [];
        $current = ['tooth' => null, 'toTooth' => null, 'words' => []];
        $close = function () use (&$findings, &$current) {
            if ($current['tooth'] !== null || $current['words'] !== []) {
                $findings[] = $current;
            }

            $current = ['tooth' => null, 'toTooth' => null, 'words' => []];
        };
        $last = null;

        foreach (explode(' ', $text) as $word) {
            if ($word === ',') {
                $close();
            } elseif ($this->isToothNumber($word)) {
                if ($current['tooth'] !== null && in_array($last, ['a', 'al', 'hasta'], true) && $current['toTooth'] === null) {
                    $current['toTooth'] = (int) $word;
                } else {
                    if ($current['tooth'] !== null) {
                        $close();
                    }

                    $current['tooth'] = (int) $word;
                }
            } elseif ($word !== '') {
                $current['words'][] = $word;
            }

            $last = $word;
        }

        $close();

        return $findings;
    }

    /**
     * @param  array{tooth: int|null, toTooth: int|null, words: list<string>}  $finding
     * @param  Collection<string, OdontogramCondition>  $conditions
     * @param  array<string, array{0: string, 1: string|null}>  $phrases
     * @param  array{code: string, abbreviation: string|null, state: string|null}|null  $previous
     * @return array<string, mixed>
     */
    private function read(array $finding, Dentition $dentition, Collection $conditions, array $phrases, ?array $previous): array
    {
        $text = trim(implode(' ', array_filter([$finding['tooth'], $finding['toTooth'] ? 'a '.$finding['toTooth'] : null, ...$finding['words']])));
        $fail = fn (string $error) => ['text' => $text, 'summary' => null, 'error' => $error, 'marks' => []];
        $words = ' '.implode(' ', $finding['words']).' ';
        $code = null;
        $abbreviation = null;

        foreach ($phrases as $phrase => [$phraseCode, $phraseAbbreviation]) {
            if (! str_contains($words, " {$phrase} ")) {
                continue;
            }

            if ($code !== null && $code !== $phraseCode) {
                return $fail('Hay dos hallazgos para la misma pieza: sepáralos con una coma.');
            }

            $code = $phraseCode;
            $abbreviation ??= $phraseAbbreviation;
            $words = str_replace(" {$phrase} ", ' ', $words);
        }

        $surfaces = [];
        $state = null;
        $unknown = [];

        foreach (preg_split('/\s+/', trim($words), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $owner = $this->abbreviationOwner(strtoupper($word), $code, $conditions);
            $letters = preg_match('/^[modvpli]{1,5}$/', $word) === 1;
            $onSurface = $code === null || $conditions[$code]?->applies_to === 'surface';

            if (($named = $this->surfaceWords($word)) !== null) {
                array_push($surfaces, ...$named);
            } elseif (in_array($word, self::GOOD_WORDS, true)) {
                $state = 'good';
            } elseif (in_array($word, self::BAD_WORDS, true)) {
                $state = 'bad';
            } elseif (in_array($word, self::STOP_WORDS, true)) {
                continue;
            } elseif ($owner && (strlen($word) > 1 || ! $letters || ! $onSurface)) {
                // "IV" o "PP" son siglas, aunque sus letras sean de caras; una letra sola es una cara.
                $code = $owner;
                $abbreviation = $this->abbreviationOf(strtoupper($word), $conditions[$owner]);
            } elseif ($letters && $onSurface) {
                array_push($surfaces, ...str_split(strtoupper($word)));
            } else {
                $unknown[] = $word;
            }
        }

        // "37 oclusal" después de una caries es otra caries; "46 algo" no es nada que se pueda adivinar.
        if ($code === null && $previous !== null && $unknown === []) {
            ['code' => $code, 'abbreviation' => $abbreviation] = $previous;
            $state ??= $previous['state'];
        }

        if ($finding['tooth'] === null) {
            return $fail('Falta el número de la pieza.');
        }

        if ($code === null || ! $conditions->has($code)) {
            return $fail('No reconocí el hallazgo.');
        }

        /** @var OdontogramCondition $condition */
        $condition = $conditions[$code];
        $tooth = $finding['tooth'];

        if (! Teeth::exists($tooth, $dentition)) {
            return $fail("La pieza {$tooth} no está en este odontograma. Cambia la dentición si hace falta.");
        }

        $abbreviation = in_array($abbreviation, $condition->abbreviations ?? [], true) ? $abbreviation : null;
        $state = $this->stateFor($condition, $state);
        $context = ['code' => $code, 'abbreviation' => $abbreviation, 'state' => $state];
        $mark = ['tooth' => $tooth, 'surface' => null, 'toTooth' => null, 'code' => $code, 'state' => $state, 'abbreviation' => $abbreviation];
        $name = $condition->name.($abbreviation ? ' ('.OdontogramAbbreviations::withWord($code, $abbreviation).')' : '');
        $color = $state === 'good' ? 'tratado' : 'por tratar';

        if ($condition->applies_to === 'span') {
            if ($finding['toTooth'] === null) {
                return $fail("Di desde qué pieza hasta cuál, por ejemplo \"{$tooth} a ".($tooth + 2).'".');
            }

            return [
                'text' => $text, 'error' => null, 'context' => $context,
                'summary' => "{$tooth} a {$finding['toTooth']} — {$name}, {$color}",
                'marks' => [[...$mark, 'toTooth' => $finding['toTooth']]],
            ];
        }

        if ($condition->applies_to === 'tooth') {
            return ['text' => $text, 'error' => null, 'context' => $context, 'summary' => "{$tooth} — {$name}, {$color}", 'marks' => [$mark]];
        }

        $surfaces = array_values(array_unique(array_map(fn (string $surface) => $this->surfaceOf($tooth, $surface), $surfaces)));

        if ($surfaces === []) {
            return $fail('Falta la cara: O, M, D, V, P o L (o "oclusal", "mesial"…).');
        }

        return [
            'text' => $text, 'error' => null, 'context' => $context,
            'summary' => "{$tooth} · ".implode(' y ', array_map(Teeth::surfaceName(...), $surfaces))." — {$name}, {$color}",
            'marks' => array_map(fn (string $surface) => [...$mark, 'surface' => $surface], $surfaces),
        ];
    }

    /**
     * Frases que se reconocen, las más largas primero para que "corona temporal" gane a "corona".
     *
     * @param  Collection<string, OdontogramCondition>  $conditions
     * @return array<string, array{0: string, 1: string|null}>
     */
    private function phrases(Collection $conditions): array
    {
        $phrases = [];

        foreach ($conditions as $code => $condition) {
            $phrases[$this->plain($condition->name)] = [$code, null];

            foreach (OdontogramAbbreviations::MEANINGS[$code] ?? [] as $abbreviation => [$word, $full]) {
                $phrases[$this->plain($full)] = [$code, $abbreviation];

                if (! in_array($this->plain($word), self::AMBIGUOUS_WORDS, true)) {
                    $phrases[$this->plain($word)] ??= [$code, $abbreviation];
                }
            }
        }

        $phrases = [...$phrases, ...self::PHRASES];
        uksort($phrases, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $phrases;
    }

    /**
     * Caras dichas con su nombre, también juntas: "mesiooclusal", "ocluso distal".
     *
     * @return list<string>|null
     */
    private function surfaceWords(string $word): ?array
    {
        $surfaces = [];

        while ($word !== '') {
            $match = collect(self::SURFACE_WORDS)->keys()->sortByDesc(fn (string $name) => strlen($name))->first(fn (string $name) => str_starts_with($word, $name));

            if ($match === null) {
                return null;
            }

            $surfaces[] = self::SURFACE_WORDS[$match];
            $word = substr($word, strlen($match));
        }

        return $surfaces;
    }

    private function plain(string $text): string
    {
        return Str::of($text)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->toString();
    }

    private function isToothNumber(string $word): bool
    {
        return preg_match('/^([1-4][1-8]|[5-8][1-5])$/', $word) === 1;
    }

    /**
     * Hallazgo al que pertenece una sigla dicha sola ("CD", "TC", "R"): el ya reconocido, o el único que la usa.
     *
     * @param  Collection<string, OdontogramCondition>  $conditions
     */
    private function abbreviationOwner(string $word, ?string $code, Collection $conditions): ?string
    {
        if ($code !== null) {
            return $this->abbreviationOf($word, $conditions[$code]) !== null ? $code : null;
        }

        $owners = $conditions->filter(fn (OdontogramCondition $condition) => $this->abbreviationOf($word, $condition) !== null);

        return $owners->count() === 1 ? $owners->keys()->first() : null;
    }

    private function abbreviationOf(string $word, ?OdontogramCondition $condition): ?string
    {
        return collect($condition?->abbreviations ?? [])->first(fn (string $abbreviation) => strtoupper($abbreviation) === $word);
    }

    /** Lo que solo existe en un color usa ese; si no se dijo, lo que puede estar bien (restauración, corona…) va en azul. */
    private function stateFor(OdontogramCondition $condition, ?string $state): string
    {
        return match (true) {
            ! $condition->allows_good_state => 'bad',
            ! $condition->allows_bad_state => 'good',
            default => $state ?? 'good',
        };
    }

    /** "Oclusal" en un incisivo es su borde incisal, y "palatina" abajo es lingual. */
    private function surfaceOf(int $tooth, string $surface): string
    {
        $surfaces = Teeth::surfaces($tooth);

        return in_array($surface, $surfaces, true) ? $surface : match ($surface) {
            'O' => 'I', 'I' => 'O', 'P' => 'L', 'L' => 'P', default => $surface,
        };
    }
}
