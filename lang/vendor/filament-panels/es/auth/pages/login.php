<?php

// En todo el sistema se trata de "tú" (la traducción de Filament usa "usted").
return [

    'heading' => 'Entra a tu cuenta',

    'actions' => [
        'request_password_reset' => [
            'label' => '¿Olvidaste tu contraseña?',
        ],
    ],

    'messages' => [
        'failed' => 'El correo o la contraseña no coinciden.',
    ],

    'notifications' => [
        'throttled' => [
            'title' => 'Demasiados intentos. Vuelve a intentarlo en :seconds segundos.',
            'body' => 'Vuelve a intentarlo en :seconds segundos.',
        ],
    ],

];
