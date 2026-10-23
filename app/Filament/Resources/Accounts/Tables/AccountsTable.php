<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Resources\Accounts\AccountActions;
use App\Models\Account;
use App\Support\Phone;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('patients')->withCount('patients'))
            ->columns([
                TextColumn::make('phone')
                    ->label('Celular')
                    ->formatStateUsing(fn (string $state) => Phone::display($state))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%'))
                    ->weight('medium'),
                TextColumn::make('patients_list')
                    ->label('Pacientes')
                    ->state(fn (Account $record) => $record->patients->map(fn ($p) => $p->full_name)->all())
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList(),
                IconColumn::make('phone_verified_at')
                    ->label('Verificado')
                    ->boolean()
                    ->state(fn (Account $record) => $record->isVerified())
                    ->tooltip(fn (Account $record) => $record->isVerified() ? 'Abrió su enlace personal' : 'Aún no abre su enlace'),
                TextColumn::make('blocked_at')
                    ->label('Reservas web')
                    ->badge()
                    ->state(fn (Account $record) => $record->isBlocked() ? 'Bloqueado' : 'Permitidas')
                    ->color(fn (string $state) => $state === 'Bloqueado' ? 'danger' : 'success')
                    ->tooltip(fn (Account $record) => $record->blocked_reason),
                TextColumn::make('last_seen_at')
                    ->label('Última vez en la web')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('blocked')
                    ->label('Solo bloqueados')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('blocked_at')),
            ])
            ->recordActions([
                ViewAction::make(),
                AccountActions::block(),
                AccountActions::unblock(),
            ]);
    }
}
