<?php

namespace App\Filament\Resources\Supplies\Pages;

use App\Filament\Resources\Supplies\SupplyActions;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\Supply;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/** Un material: lo que hay, sus lotes con vencimiento y cada entrada o salida. */
class ViewSupply extends ViewRecord
{
    protected static string $resource = SupplyResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Supply $supply */
        $supply = $this->getRecord();

        return $supply->category->getLabel().' · se cuenta en '.$supply->unit.($supply->tracks_serial ? ' · cada unidad con su número de serie' : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            SupplyActions::receive(fn () => $this->getRecord()),
            EditAction::make()->color('gray'),
        ];
    }
}
