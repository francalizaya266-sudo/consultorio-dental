<?php

namespace App\Filament\Resources\Campaigns\Tables;

use App\Enums\AppointmentStatus;
use App\Enums\DiscountType;
use App\Models\Campaign;
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
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['appointments' => fn (Builder $a) => $a->where('status', '!=', AppointmentStatus::Cancelled)]))
            ->columns([
                TextColumn::make('name')
                    ->label('Campaña')
                    ->searchable()
                    ->description(fn (Campaign $record) => $record->promo_code),
                TextColumn::make('starts_at')
                    ->label('Vigencia')
                    ->formatStateUsing(fn (Campaign $record) => $record->starts_at->format('d/m').' al '.$record->ends_at->format('d/m/Y'))
                    ->sortable(),
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
                    }),
                TextColumn::make('discount_value')
                    ->label('Descuento')
                    ->state(fn (Campaign $record) => match ($record->discount_type) {
                        DiscountType::Percent => rtrim(rtrim((string) $record->discount_value, '0'), '.').' %',
                        DiscountType::Amount => 'S/ '.number_format((float) $record->discount_value, 2),
                        default => null,
                    })
                    ->placeholder('—'),
                TextColumn::make('appointments_count')
                    ->label('Citas')
                    ->state(fn (Campaign $record) => $record->max_redemptions ? "{$record->appointments_count} / {$record->max_redemptions}" : (string) $record->appointments_count),
                TextColumn::make('views_count')
                    ->label('Visitas'),
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
