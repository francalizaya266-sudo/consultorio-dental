<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Account;
use App\Support\Phone;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('phone')
                            ->label('Celular')
                            ->formatStateUsing(fn (string $state) => Phone::display($state))
                            ->weight('bold'),
                        TextEntry::make('phone_verified_at')
                            ->label('Verificación')
                            ->state(fn (Account $record) => $record->isVerified()
                                ? 'Verificado el '.$record->phone_verified_at->format('d/m/Y')
                                : 'Aún no abre su enlace personal'),
                        TextEntry::make('blocked_at')
                            ->label('Reservas web')
                            ->badge()
                            ->state(fn (Account $record) => $record->isBlocked() ? 'Bloqueado' : 'Permitidas')
                            ->color(fn (string $state) => $state === 'Bloqueado' ? 'danger' : 'success')
                            ->helperText(fn (Account $record) => $record->isBlocked() ? $record->blocked_reason : null),
                        TextEntry::make('web_booking_limit')
                            ->label('Tope de citas web')
                            ->state(fn (Account $record) => $record->webBookingLimit().($record->web_booking_limit === null ? ' (general)' : ' (solo este celular)')),
                        TextEntry::make('accepts_promotions')
                            ->label('Promociones')
                            ->state(fn (Account $record) => $record->accepts_promotions ? 'Acepta recibirlas' : 'No acepta'),
                        TextEntry::make('last_seen_at')
                            ->label('Última vez en la web')
                            ->since()
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
