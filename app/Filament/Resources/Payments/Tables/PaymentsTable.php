<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentMethod;
use App\Models\Doctor;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('patient.last_name')
                    ->label('Paciente')
                    ->formatStateUsing(fn (Payment $record) => $record->patient->full_name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn (Builder $p) => $p->search($search))),
                TextColumn::make('treatment.name')
                    ->label('Concepto')
                    ->description(fn (Payment $record) => $record->doctor?->name)
                    ->placeholder('—'),
                TextColumn::make('method')
                    ->label('Medio')
                    ->badge()
                    ->color('gray')
                    ->description(fn (Payment $record) => $record->reference),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('PEN', locale: 'es_PE')
                    ->color(fn (Payment $record) => $record->voided_at ? 'gray' : null)
                    ->description(fn (Payment $record) => $record->voided_at ? 'Anulado: '.$record->void_reason : null)
                    ->alignEnd()
                    ->summarize(Sum::make()
                        ->label('Total (sin anulados)')
                        ->money('PEN', locale: 'es_PE')
                        ->query(fn (QueryBuilder $query) => $query->whereNull('voided_at'))),
                TextColumn::make('receivedBy.name')
                    ->label('Registró')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('paid_at', 'desc')
            ->filters([
                Filter::make('paid_at')
                    ->label('Fecha')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->default(today()),
                        DatePicker::make('until')->label('Hasta')->default(today()),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (! $from && ! $until) {
                            return [];
                        }

                        $label = $from === $until
                            ? 'Día '.now()->parse($from)->format('d/m/Y')
                            : 'Del '.($from ? now()->parse($from)->format('d/m/Y') : '…').' al '.($until ? now()->parse($until)->format('d/m/Y') : '…');

                        return [Indicator::make($label)->removeField('from')->removeField('until')];
                    }),
                SelectFilter::make('method')
                    ->label('Medio de pago')
                    ->options(PaymentMethod::class),
                SelectFilter::make('doctor_id')
                    ->label('Doctor')
                    ->options(fn () => Doctor::query()->orderBy('sort_order')->pluck('name', 'id')),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                self::voidAction(),
            ])
            ->emptyStateHeading('No hay pagos en estas fechas')
            ->emptyStateDescription('Usa "Registrar pago" cuando el paciente pague en el consultorio.');
    }

    /** Anular un pago: queda registrado (no se borra) y deja de sumar. */
    public static function voidAction(): Action
    {
        return Action::make('void')
            ->label('Anular')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Payment $record) => $record->voided_at === null)
            ->authorize('update')
            ->modalHeading('Anular pago')
            ->modalDescription('El pago queda registrado como anulado (no se borra).')
            ->modalSubmitActionLabel('Anular')
            ->schema([
                Textarea::make('reason')->label('Motivo')->required()->rows(2)->maxLength(250),
            ])
            ->action(function (array $data, Payment $record) {
                $record->update([
                    'voided_at' => now(),
                    'voided_by_user_id' => auth()->id(),
                    'void_reason' => $data['reason'],
                ]);

                Notification::make()->success()->title('Pago anulado')->send();
            });
    }
}
