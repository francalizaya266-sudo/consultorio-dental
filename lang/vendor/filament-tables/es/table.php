<?php

// Textos que la traducción al español de Filament todavía no trae (si faltan, se ve la clave en pantalla),
// y los que trae de "usted": en todo el sistema se trata de "tú".
return [

    'column_manager' => [
        'position' => 'Posición :position de :count.',
        'position_in_group' => 'Posición :position de :count en :group.',
        'reordered' => ':column se movió a la posición :position de :count.',
        'reordered_deferred' => ':column se movió a la posición :position de :count. Aplica las columnas para guardar el cambio.',
        'reordered_in_group' => ':column se movió a la posición :position de :count en :group.',
        'reordered_in_group_deferred' => ':column se movió a la posición :position de :count en :group. Aplica las columnas para guardar el cambio.',
        'actions' => [
            'move_up' => ['label' => 'Subir :column'],
            'move_down' => ['label' => 'Bajar :column'],
        ],
    ],

    'columns' => [
        'select' => [
            'placeholder' => 'Elige una opción',
            'no_search_results_message' => 'No hay opciones para tu búsqueda.',
            'search_prompt' => 'Escribe para buscar…',
        ],
        'editable' => [
            'saving' => 'Guardando…',
            'saved' => 'Cambio guardado.',
            'failure' => 'No se pudo confirmar el cambio. Recarga la página antes de volver a intentarlo.',
            'unavailable' => 'Este registro ya no se puede editar.',
        ],
    ],

    'actions' => [
        'move_record_up' => ['label' => 'Subir :label'],
        'move_record_down' => ['label' => 'Bajar :label'],
    ],

    'reordering' => [
        'position' => ':label, posición :position de :count.',
        'moved' => ':label se movió a la posición :position de :count.',
    ],

];
