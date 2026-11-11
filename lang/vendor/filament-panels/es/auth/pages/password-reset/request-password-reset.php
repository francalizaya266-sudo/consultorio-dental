<?php

// "¿Olvidaste tu contraseña?": se trata de "tú", como en el resto del sistema.
return [

    'title' => 'Recuperar tu contraseña',

    'form' => [

        'email' => [
            'label' => 'Tu correo',
        ],

        'actions' => [

            'request' => [
                'label' => 'Enviarme el enlace',
            ],

        ],

    ],

    'notifications' => [

        'sent' => [
            'body' => 'Llega solo si ese correo es de un usuario del sistema. Si no lo ves en unos minutos, revisa el correo no deseado.',
        ],

        'throttled' => [
            'title' => 'Demasiados intentos',
            'body' => 'Vuelve a intentarlo en :seconds segundos.',
        ],

    ],

];
