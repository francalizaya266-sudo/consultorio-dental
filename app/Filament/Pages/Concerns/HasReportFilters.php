<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Doctor;
use App\Models\User;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/** Filtros comunes de Resumen y Ganancias: período y, para la administración, doctor. */
trait HasReportFilters
{
    use HasFiltersForm;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return ReportAccess::canView($user);
    }

    public function filtersForm(Schema $schema): Schema
    {
        $custom = fn (Get $get) => $get('period') === 'custom';

        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(['sm' => 2, 'lg' => 4])
                ->schema([
                    Select::make('period')
                        ->label('Período')
                        ->options(ReportPeriod::PRESETS)
                        ->default('month')
                        ->selectablePlaceholder(false)
                        ->live()
                        // Al pasar a "Elegir fechas" se parte del período que se estaba viendo.
                        ->afterStateUpdated(function (?string $state, ?string $old, Set $set) {
                            if ($state === 'custom') {
                                $period = ReportPeriod::fromFilters(['period' => $old]);
                                $set('from', $period->from->toDateString());
                                $set('to', $period->to->toDateString());
                            }
                        }),
                    DatePicker::make('from')
                        ->label('Desde')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->firstDayOfWeek(1)
                        ->closeOnDateSelection()
                        ->visible($custom),
                    DatePicker::make('to')
                        ->label('Hasta')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->firstDayOfWeek(1)
                        ->closeOnDateSelection()
                        ->visible($custom),
                    Select::make('doctor')
                        ->label('Doctor')
                        ->placeholder('Todos')
                        ->options(fn () => Doctor::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn () => ReportAccess::choosesDoctor(auth()->user())),
                ]),
        ]);
    }

    protected function reportPeriod(): ReportPeriod
    {
        return ReportPeriod::fromFilters($this->filters);
    }

    protected function reportDoctorId(): ?int
    {
        return ReportAccess::doctorFor(auth()->user(), $this->filters['doctor'] ?? null);
    }

    /** @return array<string, mixed> Los filtros para los enlaces de imprimir y Excel. */
    protected function reportQuery(): array
    {
        return array_filter([
            'period' => $this->filters['period'] ?? 'month',
            'from' => ($this->filters['period'] ?? null) === 'custom' ? ($this->filters['from'] ?? null) : null,
            'to' => ($this->filters['period'] ?? null) === 'custom' ? ($this->filters['to'] ?? null) : null,
            'doctor' => $this->reportDoctorId(),
        ], fn ($value) => filled($value));
    }
}
