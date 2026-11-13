<?php

namespace App\Filament\Concerns;

use Filament\Navigation\NavigationItem;

/**
 * Las entradas de "Configuración" van en cuatro grupos (Consultorio, Catálogo, Equipo y Textos): lo de todos los
 * días no se mezcla con lo que se toca una vez al año. El menú pone el nombre del grupo sobre su primera entrada.
 */
trait HasSettingsMenuGroup
{
    /** El grupo de esta entrada, tal como se lee en el menú. */
    abstract public static function settingsMenuGroup(): string;

    /** @return array<NavigationItem> */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->extraAttributes(['data-menu-group' => static::settingsMenuGroup()]),
            parent::getNavigationItems(),
        );
    }
}
