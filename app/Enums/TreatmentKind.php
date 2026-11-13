<?php

namespace App\Enums;

use App\Models\Treatment;
use Illuminate\Support\Str;

/**
 * Qué clase de trabajo es un tratamiento: decide qué datos propios se anotan en la evolución, qué riesgos se
 * revisan antes de empezar y cada cuánto toca control. Se elige en la ficha del tratamiento; si no se elige,
 * sale de su nombre.
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

        // Lo elegido en la ficha manda sobre lo que diga el nombre.
        if ($treatment->kind !== null) {
            return $treatment->kind;
        }

        return self::fromName($treatment->name);
    }

    /** El tipo que sale del nombre cuando en la ficha no se eligió ninguno: "Profilaxis" es una limpieza. */
    public static function fromName(?string $name): self
    {
        // Solo el nombre: es lo que el consultorio ve y corrige (el slug puede quedar de un nombre anterior).
        $text = self::words((string) $name);

        foreach (self::KEYWORDS as $kind => $words) {
            foreach ($words as $word) {
                if (str_contains($text, ' '.$word)) {
                    return self::from($kind);
                }
            }
        }

        return self::Other;
    }

    /** El tipo como se lee en la ficha del tratamiento. */
    public function getLabel(): string
    {
        return match ($this) {
            self::Evaluation => 'Evaluación',
            self::Orthodontics => 'Ortodoncia',
            self::Implant => 'Implante',
            self::RootCanal => 'Endodoncia',
            self::Extraction => 'Extracción o cirugía',
            self::Whitening => 'Blanqueamiento',
            self::Cleaning => 'Limpieza',
            self::Filling => 'Curación o resina',
            self::Other => 'Otro',
        };
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

    /** Cada cuántos meses vuelve a control si esto fue lo último que se le hizo: ortodoncia cada mes, implante al año, lo demás a los 6 meses. */
    public function checkupMonths(): int
    {
        return match ($this) {
            self::Orthodontics => 1,
            self::Implant => 12,
            default => 6,
        };
    }

    /** A cuántos días se agenda la sesión siguiente: ortodoncia al mes; lo demás (endodoncia, curaciones, controles post cirugía), a la semana. */
    public function nextSessionDays(): int
    {
        return $this === self::Orthodontics ? 30 : 7;
    }

    /** "control de ortodoncia", para el aviso y la ficha. */
    public function checkupLabel(): string
    {
        return match ($this) {
            self::Orthodontics => 'control de ortodoncia',
            self::Implant => 'control del implante',
            default => 'control y limpieza',
        };
    }

    /**
     * Los tratamientos que piden control antes o después de los 6 meses, por meses: [1 => [ids de ortodoncia], 12 => [ids de implante]].
     *
     * @return array<int, list<int>>
     */
    public static function treatmentsByCheckupMonths(): array
    {
        return Treatment::withTrashed()
            ->get(['id', 'name', 'kind', 'is_evaluation', 'checkup_months'])
            ->toBase()
            ->groupBy(fn (Treatment $treatment) => $treatment->checkupMonths())
            ->except(self::Other->checkupMonths())
            ->map(fn ($treatments) => $treatments->pluck('id')->all())
            ->all();
    }

    /** Lo que se le suele pedir al paciente antes de venir, para proponerlo en la ficha del tratamiento. */
    public function suggestedBeforeInstructions(): ?string
    {
        return match ($this) {
            self::Extraction, self::Implant => 'Ven habiendo comido algo ligero y con los dientes cepillados. Si tomas medicinas todos los días, avísanos antes.',
            self::RootCanal => 'Ven habiendo comido y con los dientes cepillados. Si tienes radiografías de esa pieza, tráelas.',
            self::Whitening => 'Ven con los dientes cepillados y evita café, té y gaseosas oscuras desde hoy.',
            self::Orthodontics => 'Ven con los dientes bien cepillados y trae tus ligas o retenedores si los usas.',
            self::Cleaning, self::Filling => 'Ven con los dientes cepillados.',
            self::Evaluation => 'Si tienes radiografías o exámenes anteriores, tráelos.',
            self::Other => null,
        };
    }

    /** Los cuidados usuales después de la atención, para proponerlos en la ficha del tratamiento. */
    public function suggestedAftercare(): ?string
    {
        return match ($this) {
            self::Extraction => "- Muerde la gasa 30 minutos y hoy no escupas ni te enjuagues.\n- No fumes ni tomes con cañita por 3 días.\n- Hoy come blando y frío o tibio, y mastica por el otro lado.\n- Toma solo lo que te recetó el doctor.\n- Desde mañana cepíllate con cuidado, sin tocar la herida.",
            self::Implant => "- Ponte hielo por fuera, 10 minutos sí y 10 no, durante las primeras horas.\n- Hoy no escupas ni te enjuagues, y no fumes por una semana.\n- Come blando y frío o tibio, y mastica por el otro lado.\n- Toma solo lo que te recetó el doctor.\n- Desde mañana cepíllate con cuidado, sin tocar la zona.",
            self::RootCanal => "- No mastiques con ese lado hasta que pase la anestesia.\n- Evita lo duro en esa pieza hasta su restauración final.\n- Es normal sentir sensibilidad unos días.\n- Toma solo lo que te recetó el doctor.",
            self::Filling => "- No comas hasta que pase la anestesia, para no morderte.\n- Si al morder sientes la curación alta, avísanos para ajustarla.",
            self::Cleaning => "- Es normal sentir sensibilidad uno o dos días.\n- Cepíllate tres veces al día y usa hilo dental.",
            self::Whitening => "- Por 48 horas evita café, té, vino, gaseosas oscuras, salsas con color y fumar.\n- Es normal sentir sensibilidad uno o dos días.",
            self::Orthodontics => "- Es normal sentir presión dos o tres días.\n- Evita alimentos duros o pegajosos.\n- Cepíllate después de cada comida.",
            self::Evaluation, self::Other => null,
        };
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
     * Los datos que no pueden faltar en la evolución de este tratamiento. "remember" es el dato de la última vez
     * que conviene tener a la vista (el color de la última resina, el último arco): la clave donde se anotó.
     *
     * @return array<string, array{label: string, type: 'text'|'number'|'toggle'|'select', placeholder?: string, suffix?: string, options?: array<string, string>, remember?: string}>
     */
    public function detailFields(): array
    {
        return match ($this) {
            self::Filling => [
                'tooth' => ['label' => 'Pieza', 'type' => 'text', 'placeholder' => '36'],
                'surfaces' => ['label' => 'Caras', 'type' => 'text', 'placeholder' => 'MO'],
                'shade' => ['label' => 'Color', 'type' => 'text', 'placeholder' => 'A2', 'remember' => 'shade'],
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
                'canals' => ['label' => 'Conductos y longitudes', 'type' => 'text', 'placeholder' => 'MV 21 mm · ML 21 mm · D 22 mm', 'remember' => 'canals'],
                'medication' => ['label' => 'Medicación entre sesiones', 'type' => 'text', 'placeholder' => 'Hidróxido de calcio', 'remember' => 'medication'],
                'temporary' => ['label' => 'Sellado provisional', 'type' => 'toggle'],
            ],
            self::Implant => [
                'tooth' => ['label' => 'Zona o pieza', 'type' => 'text', 'placeholder' => '46'],
                'brand' => ['label' => 'Marca', 'type' => 'text', 'remember' => 'brand'],
                'lot' => ['label' => 'Lote', 'type' => 'text'],
                'diameter' => ['label' => 'Diámetro', 'type' => 'number', 'suffix' => 'mm'],
                'length' => ['label' => 'Largo', 'type' => 'number', 'suffix' => 'mm'],
                'torque' => ['label' => 'Torque', 'type' => 'number', 'suffix' => 'Ncm'],
            ],
            self::Whitening => [
                'shade_before' => ['label' => 'Color inicial', 'type' => 'text', 'placeholder' => 'A3', 'remember' => 'shade_after'],
                'shade_after' => ['label' => 'Color final', 'type' => 'text', 'placeholder' => 'A1'],
                'product' => ['label' => 'Producto y concentración', 'type' => 'text', 'placeholder' => 'Peróxido de hidrógeno 35 %'],
                'sensitivity' => ['label' => 'Tuvo sensibilidad', 'type' => 'toggle'],
            ],
            self::Orthodontics => [
                'archwire' => ['label' => 'Arco', 'type' => 'text', 'placeholder' => 'NiTi 0.016 superior e inferior', 'remember' => 'archwire'],
                'elastics' => ['label' => 'Ligas o cadenetas', 'type' => 'text', 'remember' => 'elastics'],
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
