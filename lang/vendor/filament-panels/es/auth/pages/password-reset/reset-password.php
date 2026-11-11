<?php

// La página a la que lleva el enlace del correo: se trata de "tú", como en el resto del sistema.
return [

    'title' => 'Pon tu contraseña nueva',

    'heading' => 'Pon tu contraseña nueva',

    'form' => [

        'email' => [
            'label' => 'Tu correo',
        ],

        'password' => [
            'label' => 'Contraseña nueva',
        ],

        'password_confirmation' => [
            'label' => 'Repite la contraseña',
        ],

        'actions' => [

            'reset' => [
                'label' => 'Guardar contraseña',
            ],

        ],

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Demasiados intentos',
            'body' => 'Vuelve a intentarlo en :seconds segundos.',
        ],

    ],

];
