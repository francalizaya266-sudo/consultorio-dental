<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\User;
use App\Services\Reports\ActivityReport;
use App\Services\Reports\EarningsReport;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Los widgets de Resumen y Ganancias leen el período y el doctor de los filtros de la página. */
trait ReadsReportFilters
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return ReportAccess::canView($user);
    }

    protected function reportPeriod(): ReportPeriod
    {
        return ReportPeriod::fromFilters($this->pageFilters);
    }

    /** Un doctor siempre ve solo lo suyo, diga lo que diga el filtro. */
    protected function reportDoctorId(): ?int
    {
        return ReportAccess::doctorFor(auth()->user(), $this->pageFilters['doctor'] ?? null);
    }

    protected function earnings(?ReportPeriod $period = null): EarningsReport
    {
        return new EarningsReport($period ?? $this->reportPeriod(), $this->reportDoctorId());
    }

    protected function activity(?ReportPeriod $period = null): ActivityReport
    {
        return new ActivityReport($period ?? $this->reportPeriod(), $this->reportDoctorId());
    }
}
