<?php

namespace App\Filament\Concerns;

/**
 * La lista con la que abre una sección no lleva ruta arriba: "Pacientes › Listado" sobre el título "Pacientes" no
 * dice nada. La ruta queda para las pantallas de adentro (la ficha, la cita), donde sí sirve para volver.
 */
trait HasNoBreadcrumbs
{
    /** @return array<string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
