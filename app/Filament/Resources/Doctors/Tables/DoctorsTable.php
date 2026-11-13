<?php

namespace App\Filament\Resources\Doctors\Tables;

use App\Filament\Resources\Doctors\Schemas\DoctorForm;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class DoctorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'schedules'])->withCount('treatments'))
            ->columns([
                ColorColumn::make('agenda_color')
                    ->label(''),
                TextColumn::make('name')
                    ->label('Doctor')
                    ->searchable()
                    // Activo pero sin horario o sin tratamientos: no se le puede dar citas, y se avisa en la lista.
                    ->description(fn (Doctor $record) => $record->is_active && ($record->schedules->isEmpty() || $record->treatments_count === 0)
                        ? new HtmlString(trim(e((string) $record->specialty).' <span class="help-warning">No se le puede agendar: falta '
                            .implode(' y ', array_filter([$record->schedules->isEmpty() ? 'su horario' : null, $record->treatments_count === 0 ? 'sus tratamientos' : null])).'.</span>'))
                        : $record->specialty),
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
                // Desactivar lo saca de la agenda y de la web: antes se pregunta y se dice cuántas citas le quedan.
                ToggleColumn::make('is_active')
                    ->label('Activo')
                    ->updateStateUsing(function (Doctor $record, mixed $state, HasActions $livewire): void {
                        if (! $state) {
                            $livewire->mountAction('deactivate', ['doctor' => $record->getKey()]);

                            return;
                        }

                        $record->update(['is_active' => true]);

                        Notification::make()->success()->title($record->name.' vuelve a atender')->body('Ya se le pueden dar citas.')->send();
                    }),
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
