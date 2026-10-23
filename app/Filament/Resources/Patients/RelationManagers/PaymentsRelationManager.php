<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Pagos del paciente en el consultorio. */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Pagos';

    protected static ?string $modelLabel = 'pago';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('viewAny', Payment::class);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['treatment', 'receivedBy']))
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Medio')
                    ->badge()
                    ->color('gray')
                    ->description(fn (Payment $record) => $record->reference),
                TextColumn::make('notes')
                    ->label('Concepto')
                    ->state(fn (Payment $record) => $record->notes ?? $record->treatment?->name)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('PEN', locale: 'es_PE')
                    ->weight('bold')
                    ->color(fn (Payment $record) => $record->voided_at ? 'gray' : null)
                    ->description(fn (Payment $record) => $record->voided_at ? 'Anulado: '.$record->void_reason : 'Recibió '.$record->receivedBy?->name)
                    ->alignEnd(),
            ])
            ->defaultSort('paid_at', 'desc')
            ->headerActions([
                RegisterPaymentAction::make(fn () => $this->getOwnerRecord()),
            ])
            ->recordActions([
                PaymentsTable::voidAction(),
            ])
            ->emptyStateHeading('Sin pagos todavía');
    }
}
