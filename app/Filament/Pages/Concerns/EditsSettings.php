<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Setting;
use App\Models\User;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

/**
 * Una hoja de ajustes guardados en la tabla settings (Ajustes y la puesta a punto de la página web):
 * se llena con lo guardado, se guarda con un botón y cada valor se convierte a su tipo.
 */
trait EditsSettings
{
    // Como en la ficha del doctor: si se sale con cambios sin guardar, el navegador pregunta antes.
    use HasUnsavedDataChangesAlert;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @return array<string, string> Cada ajuste que edita esta hoja y su tipo. */
    abstract protected static function settingKeys(): array;

    /** Lo que dice el aviso al guardar. */
    abstract protected function savedMessage(): string;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin();
    }

    public function mount(): void
    {
        $state = [];

        foreach (array_keys(static::settingKeys()) as $key) {
            Arr::set($state, $key, Setting::get($key));
        }

        $this->form->fill($state);
        $this->rememberData();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Guardar')->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (static::settingKeys() as $key => $type) {
            $value = Arr::get($state, $key);

            Setting::set($key, match ($type) {
                'int' => (int) $value,
                'bool' => (bool) $value,
                'phone' => Phone::normalize((string) $value),
                'optional_int' => filled($value) ? (int) $value : null,
                'rating' => self::rating($value),
                default => filled($value) ? (string) $value : null,
            });
        }

        $this->rememberData();

        Notification::make()->success()->title($this->savedMessage())->send();
    }

    /** Puntaje de 1 a 5 con un decimal ("4,9" o "4.9"), o null si no es válido. */
    protected static function rating(mixed $value): ?float
    {
        $normalized = str_replace(',', '.', trim((string) $value));

        if (! is_numeric($normalized) || (float) $normalized < 1 || (float) $normalized > 5) {
            return null;
        }

        return round((float) $normalized, 1);
    }
}
