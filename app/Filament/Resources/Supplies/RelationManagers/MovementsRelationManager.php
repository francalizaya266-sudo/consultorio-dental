<?php

namespace App\Filament\Resources\Supplies\RelationManagers;

use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Cada entrada y salida del material, lo más nuevo primero. */
class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Movimientos';

    protected static ?string $modelLabel = 'movimiento';

    public function table(Table $table): Table
    {
        return StockMovementResource::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supply', 'batch', 'patient', 'user']));
    }
}
