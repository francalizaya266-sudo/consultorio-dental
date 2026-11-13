<?php

/*
| Valores por defecto del consultorio. Todo lo que está en "defaults" se puede
| cambiar desde el panel (tabla settings) sin tocar código.
*/

return [

    'defaults' => [
        'clinic' => [
            'name' => env('APP_NAME', 'Clínica Vega Implant'),
            'whatsapp' => env('CLINIC_WHATSAPP', '+51918874568'),
            'address' => 'Jr. Apurímac 648',
            // Distrito o ciudad: Google lo usa para "dentista en …".
            'district' => 'Juliaca',
            // Sale al pie de las recetas.
            'slogan' => 'Tu sonrisa, nuestra pasión',
            // Razón social y RUC: salen en las hojas impresas, bajo el nombre.
            'legal_name' => null,
            'ruc' => null,
            'phone' => null,
            'email' => null,
            // Enlace de Google Maps del consultorio ("Cómo llegar") y de sus opiniones en Google.
            'maps_url' => null,
            'reviews_url' => null,
            // Puntaje y cantidad de opiniones en Google, copiados a mano (sale con estrellas en el inicio).
            'google_rating' => null,
            'google_reviews_count' => null,
            'instagram' => null,
            'facebook' => null,
            'tiktok' => null,
            // Feriados, horario de verano u otra aclaración bajo el horario de atención.
            'hours_note' => null,
        ],

        // Textos de la página de inicio.
        'web' => [
            'headline' => 'Atención dental sin esperas',
            'intro' => 'Evaluaciones, limpiezas, implantes y más. Elige tu horario aquí mismo y te confirmamos por WhatsApp.',
            'about' => null,
            // Foto real del consultorio o del equipo, arriba del inicio.
            'hero_image' => null,
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

        // Las horas de los avisos. Se cambian en Ajustes › Avisos; lo del .env es solo el valor con que nacen.
        'reminders' => [
            // Desde qué hora del día anterior (el último en que se atiende antes de la cita) queda listo el recordatorio.
            'day_before_at' => env('REMINDERS_AT', '09:00'),
            // A qué hora del día anterior una cita sin confirmar pasa a "llamar".
            'flag_unconfirmed_at' => env('FLAG_UNCONFIRMED_AT', '16:00'),
            // Cuántos días antes de que venza una cuota se prepara su aviso.
            'installment_days_before' => (int) env('INSTALLMENT_REMINDER_DAYS', 3),
        ],

        'devices' => [
            // El dispositivo deja de estar recordado tras estos días sin uso.
            'remember_days' => 120,
            // Minutos que dura la verificación reforzada (citas pasadas, datos personales).
            'elevated_minutes' => 15,
        ],

        'plans' => [
            // Días que vale el precio de un presupuesto sin aceptar (sale en la hoja y en el WhatsApp).
            'valid_days' => 30,
        ],

        'reports' => [
            // Si los doctores ven sus propias ganancias y gráficos (la administración siempre ve todo).
            'doctors_see_own' => false,
            // Meta de cobro del mes en soles, para la tarjeta de "Hoy". Null es sin meta.
            'monthly_goal' => null,
        ],

        'links' => [
            'appointment_link_days_after' => 2,
            'access_link_hours' => 72,
        ],
    ],

    'backups' => [
        // Copias diarias de la base de datos que se guardan en storage/app/private/respaldos.
        'keep' => (int) env('BACKUPS_KEEP', 14),
        // Tamaño máximo de cada .zip de radiografías, fotos y firmas: si hay más, se baja en partes.
        'files_part_mb' => (int) env('BACKUPS_FILES_PART_MB', 200),
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
