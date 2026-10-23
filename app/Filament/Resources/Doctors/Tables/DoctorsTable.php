<?php

namespace App\Filament\Resources\Doctors\Tables;

use App\Filament\Resources\Doctors\Schemas\DoctorForm;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DoctorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'schedules'])->withCount('treatments'))
            ->columns([
                ColorColumn::make('agenda_color')
                    ->label(''),
                TextColumn::make('name')
                    ->label('Doctor')
                    ->searchable()
                    ->description(fn (Doctor $record) => $record->specialty),
                TextColumn::make('user.email')
                    ->label('Usuario')
                    ->placeholder('Sin usuario')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('schedule_summary')
                    ->label('Atiende')
                    ->state(fn (Doctor $record) => $record->schedules
                        ->sortBy('weekday')
                        ->groupBy('weekday')
                        ->map(fn ($day, $weekday) => mb_substr(DoctorForm::WEEKDAYS[$weekday], 0, 3).' '.$day->sortBy('starts_at')->map(fn (DoctorSchedule $s) => substr($s->starts_at, 0, 5).'–'.substr($s->ends_at, 0, 5))->implode(', '))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('Sin horario'),
                TextColumn::make('treatments_count')
                    ->label('Tratamientos'),
                IconColumn::make('accepts_online_booking')
                    ->label('Reserva web')
                    ->boolean(),
                ToggleColumn::make('is_active')
                    ->label('Activo'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
