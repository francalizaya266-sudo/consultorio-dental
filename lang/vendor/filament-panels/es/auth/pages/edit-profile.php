<?php

// "Mi perfil": cada persona cambia su nombre, su correo y su clave. Se trata de "tú", como en el resto.
return [

    'label' => 'Mi perfil',

    'form' => [

        'email' => [
            'label' => 'Correo',
        ],

        'current_password' => [
            'below_content' => 'Por seguridad, escribe tu contraseña actual para guardar.',
        ],

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Demasiados intentos',
            'body' => 'Vuelve a intentarlo en :seconds segundos.',
        ],

    ],

];
