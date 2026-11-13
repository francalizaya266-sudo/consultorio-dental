<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Forms\WebPhotoUpload;
use App\Filament\Forms\WeeklySchedule;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use SensitiveParameter;

/**
 * "Mi perfil". Además de su nombre, correo y contraseña, cada doctor cambia aquí cómo sale en la página web
 * (foto, presentación y formación) y ve su horario de atención, que lo cambia el administrador.
 */
class EditProfile extends BaseEditProfile
{
    /** Lo que cada doctor cambia de su propia ficha. Lo demás (especialidad, colegiatura, horario) es del administrador. */
    private const OWN = ['photo_path', 'bio', 'education'];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                Section::make('Cómo sales en la página web')
                    ->description('Tu foto, tu presentación y tu formación, como las ven los pacientes en la web.')
                    ->statePath('doctor')
                    ->visible(fn () => $this->doctor() !== null)
                    ->schema([
                        WebPhotoUpload::make('photo_path', 'equipo')
                            ->label('Foto')
                            ->avatar()
                            ->imageEditor()
                            ->circleCropper()
                            ->helperText('De frente, con buena luz. Si no hay foto, la web muestra tus iniciales.'),
                        Textarea::make('bio')
                            ->label('Presentación')
                            ->rows(4)
                            ->helperText('En primera persona y cercana: a quién atiendes y cómo trabajas.'),
                        Textarea::make('education')
                            ->label('Formación')
                            ->rows(3)
                            ->placeholder("Cirujano dentista, Universidad …\nEspecialidad en …")
                            ->helperText('Una línea por estudio.'),
                    ]),
                Section::make('Tu horario de atención')
                    ->description('Lo cambia el administrador, en Configuración › Doctores. Si no es el que haces, avísale.')
                    ->inlineLabel(false)
                    ->visible(fn () => $this->doctor() !== null)
                    ->schema([
                        TextEntry::make('schedule')
                            ->hiddenLabel()
                            ->state(fn () => $this->scheduleInWords())
                            ->listWithLineBreaks()
                            ->placeholder('Todavía no tienes horario: no se te pueden dar citas.'),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'doctor' => $this->doctor()?->only(self::OWN)];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $own = Arr::pull($data, 'doctor');

        if (is_array($own)) {
            $this->doctor()?->update(Arr::only($own, self::OWN));
        }

        return parent::handleRecordUpdate($record, $data);
    }

    /** La ficha de doctor de quien entró: la tienen los doctores y el administrador que también atiende. */
    private function doctor(): ?Doctor
    {
        return once(fn () => Doctor::query()->where('user_id', $this->getUser()->getKey())->first());
    }

    /** @return list<string> "Lunes: 09:00–13:00 y 15:00–19:00", un renglón por día. */
    private function scheduleInWords(): array
    {
        return $this->doctor()?->schedules()->orderBy('weekday')->orderBy('starts_at')->get()
            ->groupBy('weekday')
            ->map(fn (Collection $ranges, int $weekday) => (WeeklySchedule::DAYS[$weekday] ?? 'Día').': '.$ranges->map(self::rangeInWords(...))->join(', ', ' y '))
            ->values()
            ->all() ?? [];
    }

    private static function rangeInWords(DoctorSchedule $range): string
    {
        $season = WeeklySchedule::seasonInWords($range->valid_from, $range->valid_until);

        return substr($range->starts_at, 0, 5).'–'.substr($range->ends_at, 0, 5).($season ? ' ('.mb_strtolower($season).')' : '');
    }
}
