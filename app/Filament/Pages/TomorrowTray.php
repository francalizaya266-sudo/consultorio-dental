<?php

namespace App\Filament\Pages;

use App\Models\Supply;
use App\Services\Inventory\Tray;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * La bandeja de mañana: cita por cita, lo que hay que preparar (con el lote que vence primero y la serie
 * del implante) y lo que hay que saber del paciente. Se abre desde Inventario; se imprime.
 */
class TomorrowTray extends Page
{
    protected string $view = 'filament.pages.tomorrow-tray';

    protected static ?string $slug = 'bandeja';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    /** El día que se prepara (por defecto, mañana). */
    #[Url]
    public string $date = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Supply::class) ?? false;
    }

    public function mount(): void
    {
        $this->date = $this->day()->toDateString();
    }

    public function getTitle(): string
    {
        return $this->day()->isTomorrow() ? 'Bandeja de mañana' : 'Bandeja del '.$this->day()->translatedFormat('l j \d\e F');
    }

    public function getSubheading(): ?string
    {
        $what = 'Lo que se usa en cada cita, del lote que vence primero.';

        return $this->day()->isTomorrow() ? ucfirst($this->day()->translatedFormat('l j \d\e F')).'. '.$what : $what;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previous')
                ->label('Día anterior')
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->action(fn () => $this->date = $this->day()->subDay()->toDateString()),
            Action::make('next')
                ->label('Día siguiente')
                ->icon(Heroicon::OutlinedChevronRight)
                ->color('gray')
                ->action(fn () => $this->date = $this->day()->addDay()->toDateString()),
            Action::make('print')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->alpineClickHandler('window.print()'),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['tray' => app(Tray::class)->for($this->day())];
    }

    private function day(): CarbonImmutable
    {
        try {
            return filled($this->date) ? CarbonImmutable::parse($this->date)->startOfDay() : CarbonImmutable::tomorrow();
        } catch (\Throwable) {
            return CarbonImmutable::tomorrow();
        }
    }
}
