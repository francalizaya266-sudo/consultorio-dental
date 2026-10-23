<?php

namespace App\Filament\Resources\Installments\Pages;

use App\Filament\Resources\Installments\InstallmentResource;
use App\Models\TreatmentPlanInstallment;
use App\Services\Billing\BillingService;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageInstallments extends ManageRecords
{
    protected static string $resource = InstallmentResource::class;

    /** @var array<string, array<int, float>> Lo que falta pagar de cada cuota, por pestaña. */
    private array $dues = [];

    public function getTabs(): array
    {
        return [
            'late' => Tab::make('Atrasadas')
                ->badge(fn () => count($this->duesFor('late')) ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey(array_keys($this->duesFor('late')))),
            'week' => Tab::make('Vencen esta semana')
                ->badge(fn () => count($this->duesFor('week')) ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey(array_keys($this->duesFor('week')))),
        ];
    }

    /** Lo que falta pagar de la cuota. */
    public function dueOf(TreatmentPlanInstallment $installment): float
    {
        return $this->duesFor('late')[$installment->getKey()] ?? $this->duesFor('week')[$installment->getKey()] ?? $installment->net();
    }

    /** @return array<int, float> */
    private function duesFor(string $tab): array
    {
        return $this->dues[$tab] ??= $tab === 'late'
            ? app(BillingService::class)->installmentsIn(['late'])
            : app(BillingService::class)->installmentsIn(['due', 'upcoming'], today()->addDays(7));
    }
}
