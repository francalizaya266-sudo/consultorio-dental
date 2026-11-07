<?php

namespace App\Filament\Resources\Installments\Pages;

use App\Filament\Resources\Installments\InstallmentResource;
use App\Models\TreatmentPlanInstallment;
use App\Services\Billing\BillingService;
use App\Support\Money;
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

    /** "Por cobrar: S/ 1,240.00 en 6 cuotas", lo de la pestaña abierta. */
    public function totalDescription(): ?string
    {
        $dues = $this->duesFor($this->activeTab === 'week' ? 'week' : 'late');

        return $dues === [] ? null : 'Por cobrar: '.Money::format(array_sum($dues)).' en '.count($dues).(count($dues) === 1 ? ' cuota' : ' cuotas');
    }

    /** @return array<int, float> */
    private function duesFor(string $tab): array
    {
        return $this->dues[$tab] ??= $tab === 'late'
            ? app(BillingService::class)->installmentsIn(['late'])
            : app(BillingService::class)->installmentsIn(['due', 'upcoming'], today()->addDays(7));
    }
}
