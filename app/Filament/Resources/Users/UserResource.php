<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/** Personas que entran al panel. Nadie se borra: se desactiva para conservar el historial. */
class UserResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'usuarios';

    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(100),
                TextInput::make('email')
                    ->label('Correo (para entrar)')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                Select::make('role')
                    ->label('Rol')
                    ->options(UserRole::class)
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get) => match ($get('role') instanceof UserRole ? $get('role') : UserRole::tryFrom((string) $get('role'))) {
                        UserRole::Admin => 'Todo el panel. Ve lo clínico solo si es odontólogo.',
                        UserRole::Reception => 'Agenda de todos, pacientes, avisos y caja. No ve lo clínico.',
                        UserRole::Doctor => 'Solo su agenda, sus pacientes y la historia clínica.',
                        default => null,
                    }),
                TextInput::make('phone')
                    ->label('Celular')
                    ->tel(),
                Toggle::make('is_dentist')
                    ->label('Es odontólogo (puede ver historias clínicas)')
                    ->visible(fn (Get $get) => in_array($get('role'), [UserRole::Admin, UserRole::Admin->value], true)),
                Toggle::make('is_active')
                    ->label('Puede entrar al panel')
                    ->default(true),
                TextInput::make('password')
                    ->label(fn (string $operation) => $operation === 'create' ? 'Contraseña' : 'Nueva contraseña')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Déjala vacía para no cambiarla.' : null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('role')
                    ->label('Rol')
                    ->badge()
                    ->description(fn (User $record) => $record->isAdmin() && $record->is_dentist ? 'Odontólogo' : null),
                TextColumn::make('last_login_at')
                    ->label('Último ingreso')
                    ->since()
                    ->placeholder('Nunca'),
                ToggleColumn::make('is_active')
                    ->label('Activo')
                    ->disabled(fn (User $record) => $record->is(auth()->user())),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
