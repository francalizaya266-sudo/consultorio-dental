<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    use HasNoBreadcrumbs;

    protected static string $resource = StockMovementResource::class;

    public function getSubheading(): ?string
    {
        return auth()->user()->canSeeClinical()
            ? 'Busca un lote o un número de serie para saber en qué pacientes se usó.'
            : 'Todo lo que entró y salió del inventario, quién lo hizo y por qué.';
    }
}
