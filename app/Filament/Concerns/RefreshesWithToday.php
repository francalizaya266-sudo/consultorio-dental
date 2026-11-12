<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\Dashboard;
use Livewire\Attributes\On;

/**
 * Las tarjetas de "Hoy" se actualizan juntas: cuando en una se hace algo (llegó, se confirmó, se cobró, se
 * agendó), las demás se vuelven a pintar al momento, sin esperar su recarga de cada 30 segundos. Así la sala de
 * espera y los números del día nunca dicen algo distinto de la agenda que está al lado.
 *
 * El mismo aviso llega cuando el cambio lo hizo otra persona en otra computadora (PanelPulse): por eso también
 * lo usa la lista de citas.
 */
trait RefreshesWithToday
{
    #[On(Dashboard::CHANGED)]
    public function refreshWithToday(): void
    {
        // Basta con recibir el aviso: Livewire vuelve a pintar la tarjeta con los datos de ahora.
    }
}
