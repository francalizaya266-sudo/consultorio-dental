<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Concerns\HasSettingsMenuGroup;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/** Personas que entran al panel. Nadie se borra: se desactiva para conservar el historial. */
class UserResource extends Resource
{
    use HasSettingsMenuGroup, HasSpanishLabels;

    protected static ?string $slug = 'usuarios';

    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    public static function settingsMenuGroup(): string
    {
        return 'Equipo';
    }

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
                    // Un doctor se crea en Doctores, con su ficha y su acceso: aquí no se puede volver doctor a nadie
                    // (quedaría sin agenda). Quien ya es doctor conserva su rol.
                    ->options(fn (?User $record) => collect(UserRole::cases())
                        ->reject(fn (UserRole $role) => $role === UserRole::Doctor && ! $record?->isDoctor())
                        ->mapWithKeys(fn (UserRole $role) => [$role->value => $role->getLabel()])
                        ->all())
                    ->required()
                    // Nadie se cambia el rol a sí mismo: un administrador podría quedarse fuera de Configuración.
                    ->disabled(fn (?User $record) => self::isMe($record))
                    ->live()
                    ->helperText(fn (Get $get) => match ($get('role') instanceof UserRole ? $get('role') : UserRole::tryFrom((string) $get('role'))) {
                        UserRole::Admin => 'Todo el panel. Ve lo clínico solo si es odontólogo.',
                        UserRole::Reception => 'Agenda de todos, pacientes, avisos y caja. No ve lo clínico.',
                        UserRole::Doctor => 'Solo su agenda, sus pacientes y la historia clínica.',
                        default => null,
                    }),
                Toggle::make('is_dentist')
                    ->label('Es odontólogo (puede ver historias clínicas)')
                    ->visible(fn (Get $get) => in_array($get('role'), [UserRole::Admin, UserRole::Admin->value], true)),
                Toggle::make('is_active')
                    ->label('Puede entrar al panel')
                    ->default(true)
                    // Ni se quita su propio acceso: si es el único administrador, nadie se lo podría devolver.
                    ->disabled(fn (?User $record) => self::isMe($record))
                    ->helperText(fn (?User $record) => self::isMe($record) ? 'Tu propio acceso y tu rol solo los puede cambiar otro administrador.' : null),
                TextInput::make('password')
                    ->label(fn (string $operation) => $operation === 'create' ? 'Contraseña' : 'Nueva contraseña')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->rule(Password::default())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => 'Mínimo 8 caracteres, con letras y números.'.($operation === 'edit' ? ' Déjala vacía para no cambiarla.' : '')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
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
                // Con el mismo nombre que en la ficha: en la lista decía "Activo" y en la ficha "Puede entrar al panel".
                ToggleColumn::make('is_active')
                    ->label('Puede entrar al panel')
                    ->disabled(fn (User $record) => self::isMe($record))
                    ->afterStateUpdated(fn (User $record, mixed $state) => Notification::make()
                        ->success()
                        ->title($record->name.($state ? ' ya puede entrar al panel' : ' ya no puede entrar al panel'))
                        ->send()),
            ])
            ->defaultSort('name')
            ->defaultPaginationPageOption(25)
            ->recordActions([
                EditAction::make()
                    ->successNotificationTitle('Usuario guardado'),
                // La misma regla que en el resto de Configuración: se borra solo a quien se registró por error y nunca
                // entró; a los demás se les quita el acceso.
                ActionGroup::make([
                    DeleteAction::make()
                        ->authorizationNotification()
                        ->modalDescription('Solo se borra a quien nunca entró al panel. A los demás se les apaga "Puede entrar al panel".')
                        ->successNotificationTitle('Usuario borrado'),
                ])->visible(fn (User $record) => ! self::isMe($record)),
            ]);
    }

    /** La ficha es la de quien está usando el panel. */
    private static function isMe(?User $record): bool
    {
        return $record !== null && $record->is(auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
