<?php

/*
| Valores por defecto del consultorio. Todo lo que está en "defaults" se puede
| cambiar desde el panel (tabla settings) sin tocar código.
*/

return [

    'defaults' => [
        'clinic' => [
            'name' => env('APP_NAME', 'Consultorio Dental'),
            'whatsapp' => env('CLINIC_WHATSAPP', '+51900000000'),
            'address' => null,
            // Distrito o ciudad: Google lo usa para "dentista en …".
            'district' => null,
            'phone' => null,
            'email' => null,
            // Enlace de Google Maps del consultorio ("Cómo llegar") y de sus opiniones en Google.
            'maps_url' => null,
            'reviews_url' => null,
            'instagram' => null,
            'facebook' => null,
            'tiktok' => null,
            // Feriados, horario de verano u otra aclaración bajo el horario de atención.
            'hours_note' => null,
        ],

        // Textos de la página de inicio.
        'web' => [
            'headline' => 'Atención dental sin esperas',
            'intro' => 'Evaluaciones, limpiezas, ortodoncia y más. Elige tu horario aquí mismo y te confirmamos por WhatsApp.',
            'about' => null,
        ],

        'agenda' => [
            // Limpieza y esterilización entre citas.
            'buffer_minutes' => 10,
            // Cada cuántos minutos se ofrecen horarios cuando no hay citas cerca.
            'slot_step_minutes' => 15,
            // Huecos menores a esto se consideran tiempo perdido.
            'min_useful_gap_minutes' => 30,
        ],

        'booking' => [
            // Anticipación mínima para reservar desde la web.
            'min_lead_minutes' => 120,
            // Hasta cuántos días adelante se puede reservar en la web.
            'horizon_days' => 45,
            'max_web_appointments_per_patient' => 2,
            'max_web_appointments_per_phone' => 6,
            // Cambio automático si faltan más de estas horas (nivel 1).
            'self_reschedule_min_hours' => 24,
            // El paciente cancela solo hasta estas horas antes.
            'self_cancel_min_hours' => 12,
            'max_reschedule_options' => 3,
        ],

        // Horas fijas del cron (se leen al arrancar, por eso vienen del .env y no del panel).
        'reminders' => [
            // A qué hora del día anterior se preparan los recordatorios.
            'day_before_at' => env('REMINDERS_AT', '09:00'),
            // A qué hora del día anterior una cita sin confirmar pasa a "llamar".
            'flag_unconfirmed_at' => env('FLAG_UNCONFIRMED_AT', '16:00'),
        ],

        'devices' => [
            // El dispositivo deja de estar recordado tras estos días sin uso.
            'remember_days' => 120,
            // Minutos que dura la verificación reforzada (citas pasadas, datos personales).
            'elevated_minutes' => 15,
        ],

        'reports' => [
            // Si los doctores ven sus propias ganancias y gráficos (la administración siempre ve todo).
            'doctors_see_own' => false,
        ],

        'links' => [
            'appointment_link_days_after' => 2,
            'access_link_hours' => 72,
        ],
    ],

    'backups' => [
        // Copias diarias de la base de datos que se guardan en storage/app/private/respaldos.
        'keep' => (int) env('BACKUPS_KEEP', 14),
    ],

    'whatsapp' => [
        // click_to_chat: el panel abre WhatsApp con el mensaje escrito (etapa 1).
        // cloud_api: los avisos salen solos por la API oficial de Meta (etapa 2).
        'driver' => env('WHATSAPP_DRIVER', 'click_to_chat'),
        'cloud_api' => [
            'token' => env('WHATSAPP_CLOUD_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        ],
    ],

];
