<?php

namespace App\Filament\Resources\Patients\Tables;

use App\Enums\AppointmentStatus;
use App\Models\Patient;
use App\Support\Phone;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withMin(
                ['appointments as next_appointment_at' => fn (Builder $a) => $a->where('starts_at', '>=', now())->whereIn('status', AppointmentStatus::changeable())],
                'starts_at',
            ))
            ->columns([
                TextColumn::make('last_name')
                    ->label('Paciente')
                    ->formatStateUsing(fn (Patient $record) => $record->full_name)
                    ->description(fn (Patient $record) => $record->document_number ? $record->document_type?->getLabel().' '.$record->document_number : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->search($search))
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('account.phone')
                    ->label('Celular')
                    ->formatStateUsing(fn (string $state) => Phone::display($state))
                    ->description(fn (Patient $record) => $record->relationship?->getLabel())
                    ->placeholder('Sin celular'),
                TextColumn::make('birth_date')
                    ->label('Edad')
                    ->formatStateUsing(fn (Patient $record) => $record->age !== null ? $record->age.' años' : null)
                    ->placeholder('—'),
                TextColumn::make('next_appointment_at')
                    ->label('Próxima cita')
                    ->dateTime('D d/m H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_visit_at')
                    ->label('Última visita')
                    ->date('d/m/Y')
                    ->placeholder('Aún no viene')
                    ->sortable(),
            ])
            ->defaultSort('last_name')
            ->filters([
                Filter::make('new')
                    ->label('Aún no vienen')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('first_visit_at')),
                TrashedFilter::make()
                    ->visible(fn () => auth()->user()->isAdmin()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('Aún no hay pacientes')
            ->emptyStateDescription('Se crean solos al reservar en la web, o desde "Nuevo paciente".');
    }
}
