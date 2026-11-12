<?php

namespace App\Livewire;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Services\Dashboard\TodayPulse;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * El pulso del panel: va en todas las pantallas y no dibuja nada. Cada pocos segundos mira si alguien guardó
 * algo del día (TodayPulse) y, si es así, avisa a la pantalla para que se vuelva a pintar: "Hoy", la agenda,
 * la lista de citas y la campana. Lo nuevo de la campana sale además como aviso en pantalla, así el doctor se
 * entera de que su paciente llegó aunque esté escribiendo una atención.
 *
 * No es tiempo real por conexión abierta (websockets): eso pide un servidor aparte siempre encendido. Una
 * consulta corta cada 3 segundos da casi lo mismo y funciona en cualquier hosting.
 */
class PanelPulse extends Component
{
    /** Cada cuánto pregunta el navegador. */
    public const EVERY = '3s';

    /** Avisos que se muestran de una vez; si llegaron más, están en la campana. */
    private const ALERTS_AT_ONCE = 3;

    /** La última marca que esta pantalla ya pintó. */
    #[Locked]
    public string $stamp = '';

    /** Los avisos de la campana creados desde este momento son nuevos para esta pantalla. */
    #[Locked]
    public string $alertsSince = '';

    /** @var list<string> Los de ese mismo segundo que ya se mostraron. */
    #[Locked]
    public array $alertsShown = [];

    public function mount(): void
    {
        $this->stamp = TodayPulse::stamp();
        $this->alertsSince = now()->toDateTimeString();
    }

    /** Lo llama el navegador cada pocos segundos. */
    public function check(): void
    {
        if ($this->catchUp()) {
            $this->dispatch(Dashboard::CHANGED);
        }
    }

    /**
     * El cambio se hizo en esta misma pantalla y sus tarjetas ya se están pintando: solo se toma nota, para no
     * pintarlas otra vez en la siguiente consulta. Lo llama el navegador al oír el aviso de la propia página; no
     * es un oyente de Livewire porque, al responderse a sí mismo, el pulso cancelaría su consulta y con ella
     * los avisos en pantalla que traía.
     */
    public function acknowledge(): void
    {
        $this->catchUp();
    }

    public function render(): View
    {
        return view('livewire.panel-pulse');
    }

    /**
     * Con cada cambio la campana también se pone al día: lo nuevo aparece y lo que ya se atendió deja de contar.
     *
     * @return bool Si alguien guardó algo desde la última vez.
     */
    private function catchUp(): bool
    {
        $this->skipRender();

        $stamp = TodayPulse::stamp();

        if ($stamp === $this->stamp) {
            return false;
        }

        $this->stamp = $stamp;
        $this->announceNewAlerts();
        $this->dispatch('databaseNotificationsSent');

        return true;
    }

    /** Lo que llegó a la campana desde la última vez se muestra también en pantalla. */
    private function announceNewAlerts(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $fresh = $user->unreadNotifications()
            ->where('data->format', 'filament')
            ->where('created_at', '>=', $this->alertsSince)
            ->whereNotIn('id', $this->alertsShown)
            ->oldest()
            ->get();

        if ($fresh->isEmpty()) {
            return;
        }

        $fresh->take(self::ALERTS_AT_ONCE)->each(fn (DatabaseNotification $alert) => Notification::make()
            ->title($alert->data['title'] ?? 'Tienes un aviso nuevo')
            ->body($alert->data['body'] ?? null)
            ->icon(Heroicon::OutlinedBell)
            ->iconColor('info')
            ->send());

        $newest = $fresh->last()->created_at->toDateTimeString();
        $ofThatSecond = $fresh->filter(fn (DatabaseNotification $alert) => $alert->created_at->toDateTimeString() === $newest)->modelKeys();

        $this->alertsShown = $newest === $this->alertsSince ? [...$this->alertsShown, ...$ofThatSecond] : $ofThatSecond;
        $this->alertsSince = $newest;
    }
}
