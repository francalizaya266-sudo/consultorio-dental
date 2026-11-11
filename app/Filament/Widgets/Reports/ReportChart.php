<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Widgets\Concerns\ReadsReportFilters;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Js;

/**
 * Base de los gráficos de reportes: una sola forma de dibujar y de escribir los números.
 *
 * Colores (en modo claro y oscuro) con significado: la serie 1 es el azul de la marca para la actividad
 * (citas, horarios, ocupación), verde para la plata que entra y rojo para lo que se pierde (inasistencias);
 * la 2, naranja. El par azul y naranja se distingue también con daltonismo, en claro y en oscuro.
 * Barras finas con la punta redondeada, sin bordes, grilla tenue y el valor completo al pasar el
 * mouse. Debajo de cada gráfico hay una tabla para lectores de pantalla.
 */
abstract class ReportChart extends ChartWidget
{
    use ReadsReportFilters;

    /** [claro, oscuro] de la serie 1 según lo que muestra el gráfico. */
    private const TONES = [
        'activity' => ['#1e4f91', '#5f87c2'],
        'money' => ['#059669', '#10b981'],
        'loss' => ['#e11d48', '#f43f5e'],
    ];

    /** [claro, oscuro] de la serie 2. */
    private const SECOND_SERIES = ['#eb6834', '#d95926'];

    /** Fondo de la tarjeta, para separar segmentos apilados con un hilo del mismo color. */
    private const SURFACE = ['#ffffff', '#0f172a'];

    /** [claro, oscuro] de las barras que no son un dato: el gris azulado del panel. */
    private const MUTED = ['#cbd5e1', '#475569'];

    protected ?string $maxHeight = '260px';

    /** Los reportes no cambian mientras se miran: se recalculan al cambiar los filtros. */
    protected ?string $pollingInterval = null;

    /** 'activity', 'money' o 'loss': de qué color va la serie principal (ver TONES). */
    protected string $tone = 'activity';

    /** @var list<string> Categorías que no son un dato (como "Sin dato"): van en gris para no competir con las demás. */
    protected array $mutedLabels = [];

    /** 'money', 'percent' o 'count'. */
    protected string $unit = 'count';

    /** Para 'count': singular y plural de lo que se cuenta. */
    protected array $noun = ['cita', 'citas'];

    protected bool $horizontal = false;

    protected bool $stacked = false;

    /** 'nearest' muestra el valor más cercano; 'index', todas las series del mismo punto (para comparar). */
    protected string $hoverMode = 'nearest';

    /**
     * La última barra es el mes en curso: va más clara y el globo dice "en curso", para que no se lea
     * como una caída al compararla con meses completos.
     */
    protected bool $lastIsInProgress = false;

    /** Antes de la etiqueta en el título del globo: "Día " convierte "5" en "Día 5". */
    protected string $tooltipTitlePrefix = '';

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float|null>}>}
     */
    abstract protected function chartData(): array;

    /** La conclusión en una línea, debajo del título. */
    abstract protected function finding(): ?string;

    protected function getData(): array
    {
        return $this->chartData();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): string|Htmlable|null
    {
        return $this->isEmpty() ? null : $this->finding();
    }

    public function isEmpty(): bool
    {
        return collect($this->getCachedData()['datasets'] ?? [])
            ->flatMap(fn (array $dataset) => $dataset['data'])
            ->filter(fn ($value) => $value !== null && (float) $value != 0.0)
            ->isEmpty();
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return 'Aún no hay datos para este período';
    }

    public function getEmptyStateIcon(): string
    {
        return 'heroicon-o-chart-bar';
    }

    public function getChartAssistiveContent(): View
    {
        $data = $this->getCachedData();

        return view('filament.widgets.chart-table', [
            'caption' => $this->getHeading(),
            'labels' => $data['labels'],
            'datasets' => $data['datasets'],
            'format' => fn ($value) => $value === null ? '—' : $this->formatValue((float) $value),
        ]);
    }

    protected function formatValue(float $value): string
    {
        return match ($this->unit) {
            'money' => 'S/ '.number_format($value, 2),
            'percent' => rtrim(rtrim(number_format($value, 1), '0'), '.').' %',
            default => number_format($value).' '.($value == 1 ? $this->noun[0] : $this->noun[1]),
        };
    }

    /** @return list<int|float|null> Los valores de la serie $index. */
    protected function values(int $index = 0): array
    {
        return $this->getCachedData()['datasets'][$index]['data'] ?? [];
    }

    /** @return array<string, int|float|null> Etiqueta => valor de la serie $index. */
    protected function pairs(int $index = 0): array
    {
        return array_combine($this->getCachedData()['labels'], $this->values($index));
    }

    protected function getOptions(): RawJs
    {
        $valueAxis = $this->horizontal ? 'x' : 'y';
        $categoryAxis = $this->horizontal ? 'y' : 'x';
        $config = Js::from([
            'colors' => [self::TONES[$this->tone], self::SECOND_SERIES],
            'surface' => self::SURFACE,
            'mutedColor' => self::MUTED,
            'unit' => $this->unit,
            'noun' => $this->noun,
            'valueAxis' => $valueAxis,
            'legend' => count($this->getCachedData()['datasets']) > 1,
            'stacked' => $this->stacked,
            'horizontal' => $this->horizontal,
            'max' => $this->unit === 'percent' && $this->horizontal ? 100 : null,
            'hoverMode' => $this->hoverMode,
            'muted' => $this->mutedLabels,
            'titlePrefix' => $this->tooltipTitlePrefix,
            'inProgress' => $this->lastIsInProgress,
        ]);

        // Sin comillas dobles: el resultado va dentro de un atributo HTML.
        return RawJs::make(<<<JS
            (() => {
                const c = {$config};
                const dark = () => document.documentElement.classList.contains('dark');
                const color = (i) => c.colors[i % c.colors.length][dark() ? 1 : 0];
                const muted = (ctx) => c.muted.includes(ctx.chart.data.labels[ctx.dataIndex]);
                const isLast = (chart, index) => index === chart.data.labels.length - 1;
                const inProgress = (ctx) => c.inProgress && isLast(ctx.chart, ctx.dataIndex);
                const barColor = (ctx) => muted(ctx) ? c.mutedColor[dark() ? 1 : 0] : color(ctx.datasetIndex) + (inProgress(ctx) ? '66' : '');
                const whole = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 });
                const decimal = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 1 });
                const money = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const full = (v) => c.unit === 'money' ? 'S/ ' + money.format(v)
                    : c.unit === 'percent' ? decimal.format(v) + ' %'
                    : whole.format(v) + ' ' + (v === 1 ? c.noun[0] : c.noun[1]);
                const tick = (v) => c.unit === 'money' ? 'S/ ' + whole.format(v)
                    : c.unit === 'percent' ? whole.format(v) + ' %'
                    : whole.format(v);

                return {
                    indexAxis: '{$categoryAxis}',
                    interaction: { mode: c.hoverMode, axis: '{$categoryAxis}', intersect: false },
                    plugins: {
                        legend: { display: c.legend },
                        tooltip: {
                            callbacks: {
                                title: (items) => items.length
                                    ? c.titlePrefix + items[0].label + (c.inProgress && isLast(items[0].chart, items[0].dataIndex) ? ' · en curso' : '')
                                    : '',
                                label: (ctx) => {
                                    const v = ctx.parsed[c.valueAxis];
                                    return (c.legend ? ctx.dataset.label + ': ' : ' ') + full(v);
                                },
                            },
                        },
                    },
                    scales: {
                        {$valueAxis}: {
                            beginAtZero: true,
                            stacked: c.stacked,
                            max: c.max ?? undefined,
                            grid: { display: true },
                            ticks: { precision: 0, callback: tick, maxTicksLimit: 6, maxRotation: 0 },
                        },
                        {$categoryAxis}: {
                            stacked: c.stacked,
                            grid: { display: false },
                            ticks: {
                                maxRotation: 0,
                                // De lado se ven todos los nombres (los largos, cortados; completos al pasar el mouse).
                                autoSkip: ! c.horizontal,
                                callback: function (value) {
                                    const label = String(this.getLabelForValue(value));
                                    return c.horizontal && label.length > 22 ? label.slice(0, 21) + '…' : label;
                                },
                            },
                        },
                    },
                    datasets: {
                        bar: {
                            backgroundColor: barColor,
                            hoverBackgroundColor: barColor,
                            borderWidth: c.stacked ? { top: 2 } : 0,
                            borderColor: () => c.surface[dark() ? 1 : 0],
                            borderRadius: c.stacked ? 0 : 4,
                            borderSkipped: 'start',
                            maxBarThickness: 28,
                        },
                        line: {
                            borderColor: (ctx) => color(ctx.datasetIndex),
                            backgroundColor: (ctx) => color(ctx.datasetIndex),
                            pointBackgroundColor: (ctx) => color(ctx.datasetIndex),
                            pointBorderColor: () => c.surface[dark() ? 1 : 0],
                            borderWidth: 2,
                            pointRadius: 4,
                            pointBorderWidth: 2,
                            pointHoverRadius: 6,
                            tension: 0,
                            fill: false,
                        },
                    },
                };
            })()
            JS);
    }
}
