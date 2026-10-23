<?php

namespace App\Filament\Resources\Doctors\Schemas;

use App\Enums\UserRole;
use App\Filament\Forms\WebPhotoUpload;
use App\Models\Doctor;
use App\Models\User;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DoctorForm
{
    public const WEEKDAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Perfil')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre como lo verá el paciente')
                            ->placeholder('Dra. Ana Torres')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                        TextInput::make('slug')
                            ->label('Dirección en la web')
                            ->prefix('/equipo#')
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        Select::make('user_id')
                            ->label('Usuario del panel')
                            ->helperText('Con este usuario el doctor entra a ver su agenda.')
                            ->options(fn (?Doctor $record) => User::query()
                                ->where(fn (Builder $q) => $q->where('role', UserRole::Doctor)->orWhere(fn (Builder $a) => $a->where('role', UserRole::Admin)->where('is_dentist', true)))
                                ->where(fn (Builder $q) => $q->whereDoesntHave('doctor')->when($record, fn (Builder $w) => $w->orWhere('id', $record->user_id)))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->default(fn () => request()->integer('user') ?: null)
                            ->searchable(),
                        TextInput::make('specialty')
                            ->label('Especialidad')
                            ->placeholder('Odontología general'),
                        TextInput::make('cop_number')
                            ->label('COP'),
                        TextInput::make('rne_number')
                            ->label('RNE (especialista)'),
                        ColorPicker::make('agenda_color')
                            ->label('Color en la agenda'),
                        WebPhotoUpload::make('photo_path', 'equipo')
                            ->label('Foto para la web')
                            ->avatar()
                            ->imageEditor()
                            ->circleCropper()
                            ->helperText('De frente, con buena luz. Si no hay foto, la web muestra sus iniciales.'),
                        Textarea::make('bio')
                            ->label('Presentación para la web')
                            ->rows(4)
                            ->helperText('En primera persona y cercana: a quién atiende y cómo trabaja.'),
                        Textarea::make('education')
                            ->label('Formación')
                            ->rows(3)
                            ->placeholder("Cirujano dentista, Universidad …\nEspecialidad en …")
                            ->helperText('Una línea por estudio.')
                            ->columnSpanFull(),
                        Toggle::make('accepts_online_booking')
                            ->label('Recibe reservas desde la web')
                            ->default(true),
                        Toggle::make('is_public')
                            ->label('Aparece en la web')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true),
                    ]),
                Section::make('Horario de atención')
                    ->description('Puedes poner varios tramos por día (por ejemplo, mañana y tarde). Almuerzos, cursos y vacaciones van en "Bloqueos de horario".')
                    ->schema([
                        Repeater::make('schedules')
                            ->hiddenLabel()
                            ->relationship(modifyQueryUsing: fn (Builder $query) => $query->orderBy('weekday')->orderBy('starts_at'))
                            ->columns(3)
                            ->schema([
                                Select::make('weekday')
                                    ->label('Día')
                                    ->options(self::WEEKDAYS)
                                    ->required(),
                                TimePicker::make('starts_at')
                                    ->label('Desde')
                                    ->seconds(false)
                                    ->required(),
                                TimePicker::make('ends_at')
                                    ->label('Hasta')
                                    ->seconds(false)
                                    ->after('starts_at')
                                    ->required(),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('Agregar tramo')
                            ->reorderable(false)
                            ->itemLabel(fn (array $state) => self::WEEKDAYS[$state['weekday'] ?? 0] ?? null),
                    ]),
            ]);
    }
}
