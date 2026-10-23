<?php

namespace App\Filament\Resources\Campaigns\Tables;

use App\Enums\AppointmentStatus;
use App\Enums\DiscountType;
use App\Models\Campaign;
use App\Services\Reports\CampaignReport;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        // Lo que trajo cada campaña, calculado una vez por fila.
        $reports = [];
        $report = function (Campaign $campaign) use (&$reports): array {
            return $reports[$campaign->getKey()] ??= app(CampaignReport::class)->of($campaign);
        };

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('package')->withCount(['appointments' => fn (Builder $a) => $a->where('status', '!=', AppointmentStatus::Cancelled)]))
            ->columns([
                TextColumn::make('name')
                    ->label('Campaña')
                    ->searchable()
                    // Qué ofrece: el código y el descuento, o el paquete.
                    ->description(fn (Campaign $record) => collect([
                        $record->promo_code,
                        match ($record->discount_type) {
                            DiscountType::Percent => rtrim(rtrim((string) $record->discount_value, '0'), '.').' %',
                            DiscountType::Amount => 'S/ '.number_format((float) $record->discount_value, 2),
                            default => null,
                        },
                        $record->package ? 'Paquete '.$record->package->name : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->wrap(),
                TextColumn::make('phase')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Campaign $record) => match (true) {
                        ! $record->is_active => 'Pausada',
                        $record->starts_at->isFuture() => 'Programada',
                        $record->ends_at->isPast() => 'Terminada',
                        default => 'Publicada',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Publicada' => 'success',
                        'Programada' => 'info',
                        default => 'gray',
                    })
                    ->description(fn (Campaign $record) => $record->starts_at->format('d/m').' al '.$record->ends_at->format('d/m/Y')),
                TextColumn::make('appointments_count')
                    ->label('Citas')
                    ->state(fn (Campaign $record) => $record->max_redemptions && $record->treatment_package_id === null ? "{$record->appointments_count} / {$record->max_redemptions}" : (string) $record->appointments_count)
                    ->description(fn (Campaign $record) => $record->views_count.($record->views_count === 1 ? ' visita' : ' visitas')),
                TextColumn::make('patients')
                    ->label('Pacientes')
                    ->state(fn (Campaign $record) => $report($record)['attended'].' atendidos')
                    ->description(fn (Campaign $record) => $report($record)['new_patients'].' nuevos'),
                TextColumn::make('accepted')
                    ->label('Aceptaron')
                    ->state(fn (Campaign $record) => $record->treatment_package_id === null ? null
                        : $report($record)['accepted'].($record->max_redemptions ? ' / '.$record->max_redemptions : ''))
                    ->tooltip('Personas que aceptaron el paquete; en un 2x1 cuentan las dos. Con paquete, los cupos cuentan a estas personas y no las citas.')
                    ->placeholder('—'),
                TextColumn::make('sold')
                    ->label('Vendido')
                    ->state(fn (Campaign $record) => Money::format($report($record)['sold']))
                    ->description(fn (Campaign $record) => $report($record)['discount'] > 0 ? 'Descuento '.Money::format($report($record)['discount']) : null),
                ToggleColumn::make('is_active')
                    ->label('Activa'),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
