<?php

namespace App\Filament\Resources\Accounts;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\PatientsRelationManager;
use App\Filament\Resources\Accounts\Schemas\AccountInfolist;
use App\Filament\Resources\Accounts\Tables\AccountsTable;
use App\Models\Account;
use App\Support\Phone;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Un celular con su familia: quién reserva, a quién se le avisa y si puede reservar en la web. */
class AccountResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'celulares';

    protected static ?string $model = Account::class;

    protected static ?string $modelLabel = 'celular';

    protected static ?string $pluralModelLabel = 'celulares';

    protected static ?string $navigationLabel = 'Celulares y familias';

    protected static string|UnitEnum|null $navigationGroup = 'Pacientes';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    public static function getRecordTitle(?Model $record): string
    {
        /** @var Account|null $record */
        return $record ? Phone::display($record->phone) : 'Celular';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('web_booking_limit')
                    ->label('Tope de citas web para este celular')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(50)
                    ->placeholder(fn () => 'Por defecto')
                    ->helperText('Déjalo vacío para usar el tope general del consultorio.'),
                Toggle::make('accepts_promotions')
                    ->label('Acepta recibir promociones')
                    ->inline(false),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PatientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'view' => ViewAccount::route('/{record}'),
            'edit' => EditAccount::route('/{record}/editar'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
