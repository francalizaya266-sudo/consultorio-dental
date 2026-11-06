<?php

namespace App\Enums;

use App\Models\Treatment;
use Illuminate\Support\Str;

/**
 * Qué clase de trabajo es un tratamiento, elegida por su nombre (no hay que configurarla): decide qué datos
 * propios se anotan en la evolución y qué riesgos se revisan antes de empezar.
 */
enum TreatmentKind: string
{
    case Evaluation = 'evaluation';
    case Orthodontics = 'orthodontics';
    case Implant = 'implant';
    case RootCanal = 'root_canal';
    case Extraction = 'extraction';
    case Whitening = 'whitening';
    case Cleaning = 'cleaning';
    case Filling = 'filling';
    case Other = 'other';

    /** @var array<string, list<string>> Clase → comienzos de palabra (sin tildes) que la eligen, en orden de prioridad. */
    private const KEYWORDS = [
        'evaluation' => ['evaluac', 'diagnost', 'primera consulta'],
        'orthodontics' => ['ortodon', 'bracket', 'alineador', 'retenedor'],
        'implant' => ['implant'],
        'root_canal' => ['endodon', 'conducto', 'pulpectom', 'pulpotom'],
        'extraction' => ['extracc', 'exodon', 'cirugia', 'muela del juicio', 'tercera molar', 'terceras molares'],
        'whitening' => ['blanquea'],
        'cleaning' => ['limpieza', 'profilaxis', 'destartraje', 'raspaje', 'curetaje', 'periodon'],
        'filling' => ['resina', 'curacion', 'obturac', 'restaurac', 'amalgama', 'ionomero', 'incrustac'],
    ];

    public static function for(?Treatment $treatment): self
    {
        if (! $treatment) {
            return self::Other;
        }

        if ($treatment->is_evaluation) {
            return self::Evaluation;
        }

        // Solo el nombre: es lo que el consultorio ve y corrige (el slug puede quedar de un nombre anterior).
        $text = self::words($treatment->name);

        foreach (self::KEYWORDS as $kind => $words) {
            foreach ($words as $word) {
                if (str_contains($text, ' '.$word)) {
                    return self::from($kind);
                }
            }
        }

        return self::Other;
    }

    /** Cita de control: "Control de ortodoncia", "Control post extracción". */
    public static function isControl(?Treatment $treatment): bool
    {
        return $treatment !== null && str_contains(self::words($treatment->name), ' control');
    }

    /** Cita de urgencia por su nombre. */
    public static function isUrgent(?Treatment $treatment): bool
    {
        $text = $treatment ? self::words($treatment->name) : '';

        return str_contains($text, ' urgenc') || str_contains($text, ' emergenc');
    }

    /** Cirugía: sangra y cicatriza (extracción, implante). */
    public function isSurgical(): bool
    {
        return in_array($this, [self::Extraction, self::Implant], true);
    }

    /** Sangra algo: las cirugías, la limpieza y la endodoncia. */
    public function bleeds(): bool
    {
        return $this->isSurgical() || in_array($this, [self::Cleaning, self::RootCanal], true);
    }

    /** Suele llevar anestesia local. */
    public function usesAnesthesia(): bool
    {
        return in_array($this, [self::Filling, self::Extraction, self::RootCanal, self::Implant], true);
    }

    /** Se trabaja una pieza: la evolución empieza por ella y la sesión del plan la trae. */
    public function hasTooth(): bool
    {
        return array_key_exists('tooth', $this->detailFields());
    }

    /**
     * Los datos que no pueden faltar en la evolución de este tratamiento.
     *
     * @return array<string, array{label: string, type: 'text'|'number'|'toggle'|'select', placeholder?: string, suffix?: string, options?: array<string, string>}>
     */
    public function detailFields(): array
    {
        return match ($this) {
            self::Filling => [
                'tooth' => ['label' => 'Pieza', 'type' => 'text', 'placeholder' => '36'],
                'surfaces' => ['label' => 'Caras', 'type' => 'text', 'placeholder' => 'MO'],
                'shade' => ['label' => 'Color', 'type' => 'text', 'placeholder' => 'A2'],
                'anesthesia' => ['label' => 'Con anestesia', 'type' => 'toggle'],
                'pulp_protection' => ['label' => 'Protección del nervio', 'type' => 'toggle'],
            ],
            self::Extraction => [
                'tooth' => ['label' => 'Pieza', 'type' => 'text', 'placeholder' => '38'],
                'anesthetic' => ['label' => 'Anestesia', 'type' => 'text', 'placeholder' => 'Lidocaína 2 %'],
                'cartridges' => ['label' => 'Cartuchos', 'type' => 'number'],
                'suture' => ['label' => 'Con sutura', 'type' => 'toggle'],
                'complications' => ['label' => 'Complicaciones', 'type' => 'text', 'placeholder' => 'Ninguna'],
            ],
            self::RootCanal => [
                'tooth' => ['label' => 'Pieza', 'type' => 'text', 'placeholder' => '46'],
                'canals' => ['label' => 'Conductos y longitudes', 'type' => 'text', 'placeholder' => 'MV 21 mm · ML 21 mm · D 22 mm'],
                'medication' => ['label' => 'Medicación entre sesiones', 'type' => 'text', 'placeholder' => 'Hidróxido de calcio'],
                'temporary' => ['label' => 'Sellado provisional', 'type' => 'toggle'],
            ],
            self::Implant => [
                'tooth' => ['label' => 'Zona o pieza', 'type' => 'text', 'placeholder' => '46'],
                'brand' => ['label' => 'Marca', 'type' => 'text'],
                'lot' => ['label' => 'Lote', 'type' => 'text'],
                'diameter' => ['label' => 'Diámetro', 'type' => 'number', 'suffix' => 'mm'],
                'length' => ['label' => 'Largo', 'type' => 'number', 'suffix' => 'mm'],
                'torque' => ['label' => 'Torque', 'type' => 'number', 'suffix' => 'Ncm'],
            ],
            self::Whitening => [
                'shade_before' => ['label' => 'Color inicial', 'type' => 'text', 'placeholder' => 'A3'],
                'shade_after' => ['label' => 'Color final', 'type' => 'text', 'placeholder' => 'A1'],
                'product' => ['label' => 'Producto y concentración', 'type' => 'text', 'placeholder' => 'Peróxido de hidrógeno 35 %'],
                'sensitivity' => ['label' => 'Tuvo sensibilidad', 'type' => 'toggle'],
            ],
            self::Orthodontics => [
                'archwire' => ['label' => 'Arco', 'type' => 'text', 'placeholder' => 'NiTi 0.016 superior e inferior'],
                'elastics' => ['label' => 'Ligas o cadenetas', 'type' => 'text'],
                'rebonded' => ['label' => 'Piezas recementadas', 'type' => 'text', 'placeholder' => 'Ninguna'],
                'hygiene' => ['label' => 'Higiene', 'type' => 'select', 'options' => ['good' => 'Buena', 'fair' => 'Regular', 'poor' => 'Mala']],
            ],
            self::Cleaning => [
                'calculus' => ['label' => 'Sarro', 'type' => 'select', 'options' => ['little' => 'Poco', 'moderate' => 'Moderado', 'heavy' => 'Abundante']],
                'bleeding' => ['label' => 'Sangrado de encías', 'type' => 'select', 'options' => ['none' => 'No', 'mild' => 'Leve', 'heavy' => 'Abundante']],
                'watch' => ['label' => 'Zonas a vigilar', 'type' => 'text', 'placeholder' => 'Bolsa en 47'],
            ],
            self::Evaluation, self::Other => [],
        };
    }

    /**
     * Los datos anotados en una línea: "Pieza 36 · Caras MO · Color A2 · Con anestesia".
     *
     * @param  array<string, mixed>|null  $details
     */
    public function summary(?array $details): ?string
    {
        $parts = collect($this->detailFields())
            ->map(function (array $field, string $key) use ($details) {
                $value = $details[$key] ?? null;

                return match (true) {
                    $field['type'] === 'toggle' => $value ? $field['label'] : null,
                    blank($value) => null,
                    $field['type'] === 'select' => $field['label'].' '.mb_strtolower($field['options'][$value] ?? (string) $value),
                    default => $field['label'].' '.$value.(isset($field['suffix']) ? ' '.$field['suffix'] : ''),
                };
            })
            ->filter();

        return $parts->isEmpty() ? null : $parts->implode(' · ');
    }

    private static function words(string $text): string
    {
        // Por el comienzo de cada palabra: "encía" no debe encontrarse dentro de "urgencia".
        return ' '.Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
