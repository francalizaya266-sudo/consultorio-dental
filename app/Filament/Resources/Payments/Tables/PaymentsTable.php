<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentMethod;
use App\Filament\Actions\RegisterPaymentAction;
use App\Models\Doctor;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
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
            ->stackedOnMobile()
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
                    ->state(fn (Payment $record) => (float) $record->amount < 0 ? 'Devolución' : $record->treatment?->name)
                    ->description(fn (Payment $record) => (float) $record->amount < 0 ? $record->notes : $record->doctor?->name)
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
                        // Una semana por defecto: el día solo ya está en "Cierre del día" y un día sin pagos dejaba la lista vacía.
                        // Con el calendario del panel, como en Cierre del día y Reportes: dd/mm/aaaa en cualquier computadora.
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y')->firstDayOfWeek(1)->closeOnDateSelection()->default(today()->subDays(6)->toDateString()),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y')->firstDayOfWeek(1)->closeOnDateSelection()->default(today()->toDateString()),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(self::day($data['from'] ?? null), fn (Builder $q, $date) => $q->whereDate('paid_at', '>=', $date))
                        ->when(self::day($data['until'] ?? null), fn (Builder $q, $date) => $q->whereDate('paid_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $from = self::day($data['from'] ?? null);
                        $until = self::day($data['until'] ?? null);

                        if (! $from && ! $until) {
                            return [];
                        }

                        if ($from === today()->subDays(6)->toDateString() && $until === today()->toDateString()) {
                            return [Indicator::make('Últimos 7 días')->removeField('from')->removeField('until')];
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
                self::receiptAction(),
                self::voidAction(),
            ])
            ->emptyStateHeading('No hay pagos en estas fechas')
            ->emptyStateDescription('Usa "Cobrar" cuando el paciente pague en el consultorio, o mira los pagos de otros días.')
            // Un día sin pagos no deja la pantalla en blanco: un clic quita el filtro de fecha.
            ->emptyStateActions([
                Action::make('allDates')
                    ->label('Ver pagos de todos los días')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('gray')
                    ->visible(fn (HasTable $livewire) => filled($livewire->getTableFilterState('paid_at')['from'] ?? null) || filled($livewire->getTableFilterState('paid_at')['until'] ?? null))
                    ->action(fn (HasTable $livewire) => $livewire->removeTableFilter('paid_at')),
            ]);
    }

    /** La constancia del pago, para imprimirla o enviarla por WhatsApp. */
    public static function receiptAction(): Action
    {
        return Action::make('receipt')
            ->label('Constancia')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->url(fn (Payment $record) => RegisterPaymentAction::receiptUrl($record), shouldOpenInNewTab: true);
    }

    /** El calendario del panel guarda "2026-11-01 00:00:00": para filtrar por día basta "2026-11-01". */
    private static function day(?string $value): ?string
    {
        return filled($value) ? CarbonImmutable::parse($value)->toDateString() : null;
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
            // En un día cerrado se ve, apagado, con el porqué.
            ->authorizationTooltip()
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
