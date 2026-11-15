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
            // La frase corta que va arriba del título del inicio. Vacía en la puesta a punto = no sale.
            'tagline' => 'Innovación y cuidado dental',
            'headline' => 'Atención dental sin esperas',
            'intro' => 'Evaluaciones, limpiezas, implantes y más. Elige tu horario aquí mismo y te confirmamos por WhatsApp.',
            'about' => null,
            // Foto real del consultorio o del equipo, arriba del inicio.
            'hero_image' => null,
            // El encabezado y la franja de cierre de cada página (Página web › Textos de las páginas). En el título,
            // la última palabra sale en azul; para elegir otra parte se encierra entre asteriscos.
            'pages' => [
                // El cierre del inicio y de las páginas que no tienen el suyo.
                'inicio' => [
                    'closing_title' => 'Tu salud bucal, en buenas manos',
                    'closing_text' => 'Reserva tu cita hoy y luce una sonrisa saludable.',
                ],
                'servicios' => [
                    'kicker' => 'Tu sonrisa, nuestra prioridad',
                    'title' => 'Nuestros servicios',
                    'lead' => 'Cuánto dura cada uno y desde cuánto cuesta. El precio exacto te lo da el doctor en tu evaluación, antes de empezar, y se paga en el consultorio.',
                    'photo' => null,
                    'closing_title' => '¿Listo para cuidar tu sonrisa?',
                    'closing_text' => 'Reserva tu cita en línea y te confirmamos por WhatsApp.',
                ],
                'equipo' => [
                    'kicker' => 'Profesionales comprometidos con tu salud',
                    'title' => 'Nuestro equipo',
                    'lead' => 'Conoce a quienes te atienden: su especialidad, su formación y su número de colegiatura.',
                    'photo' => null,
                    'closing_title' => 'Reserva tu cita con nuestro equipo',
                    'closing_text' => 'Estamos listos para cuidar de tu sonrisa.',
                ],
                'contacto' => [
                    'kicker' => 'Estamos aquí para ayudarte',
                    'title' => 'Contácta*nos*',
                    'lead' => 'Escríbenos, llámanos o visítanos. Con gusto resolvemos tus dudas y reservamos tu cita.',
                    'photo' => null,
                    'closing_title' => 'Reserva tu cita hoy',
                    'closing_text' => 'Elige el día y la hora en línea; te confirmamos por WhatsApp.',
                ],
                'preguntas' => [
                    'kicker' => 'Resolvemos tus dudas',
                    'title' => 'Preguntas frecuentes',
                    'lead' => 'Encuentra aquí las respuestas a las dudas más comunes sobre nuestros servicios y tus citas.',
                    'photo' => null,
                    'closing_title' => '¿No encontraste tu respuesta?',
                    'closing_text' => 'Escríbenos y con gusto te ayudamos.',
                ],
                'promociones' => [
                    'kicker' => 'Ofertas vigentes',
                    'title' => 'Promociones',
                    'lead' => 'Las promociones se aplican al pagar en el consultorio. Reserva desde aquí y el código queda guardado en tu cita.',
                    'photo' => null,
                ],
                'casos' => [
                    'kicker' => 'Resultados reales',
                    'title' => 'Antes y después',
                    'lead' => 'Resultados de pacientes que nos autorizaron a mostrar sus fotos. Cada caso es distinto: en tu evaluación el doctor te dice qué puedes esperar.',
                    'photo' => null,
                ],
                'opiniones' => [
                    'kicker' => 'Lo que dicen nuestros pacientes',
                    'title' => 'Opiniones de pacientes',
                    'lead' => 'Copiadas tal como las escribieron nuestros pacientes.',
                    'photo' => null,
                ],
            ],
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

        'backups' => [
            // Cuántas copias diarias de la base de datos se guardan en storage/app/private/respaldos. Se cambia en
            // Copias de seguridad; lo del .env es solo el valor con que nace.
            'keep' => (int) env('BACKUPS_KEEP', 14),
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
