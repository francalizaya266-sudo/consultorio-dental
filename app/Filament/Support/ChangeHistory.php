<?php

namespace App\Filament\Support;

use App\Enums\MessageTemplateKey;
use App\Enums\TreatmentKind;
use App\Enums\UserRole;
use App\Filament\Forms\WeeklySchedule;
use App\Filament\Pages\Settings;
use App\Models\AuditLog;
use App\Models\ConsentTemplate;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * El historial de cambios de Configuración dicho con palabras: de cada registro guardado saca dónde fue, sobre qué
 * y qué cambió ("Duración de la cita: 30 → 45"), con los mismos nombres que tienen los datos en cada pantalla.
 */
final class ChangeHistory
{
    /** @var array<class-string, string> Lo que entra al historial y la sección de Configuración en la que se cambia. */
    public const SECTIONS = [
        Setting::class => 'Ajustes y página web',
        User::class => 'Usuarios',
        Doctor::class => 'Doctores',
        DoctorSchedule::class => 'Doctores',
        Treatment::class => 'Tratamientos',
        TreatmentPackage::class => 'Paquetes',
        ConsentTemplate::class => 'Textos de consentimiento',
        MessageTemplate::class => 'Textos de aviso',
    ];

    /** @var array<string, string> Cada ajuste (de Ajustes y de la puesta a punto de la página web) con su nombre en pantalla. */
    public const SETTINGS = [
        'clinic.name' => 'Nombre del consultorio',
        'clinic.whatsapp' => 'WhatsApp del consultorio',
        'clinic.phone' => 'Teléfono fijo',
        'clinic.address' => 'Dirección',
        'clinic.district' => 'Distrito o ciudad',
        'clinic.email' => 'Correo',
        'clinic.slogan' => 'Lema',
        'clinic.legal_name' => 'Razón social',
        'clinic.ruc' => 'RUC',
        'agenda.buffer_minutes' => 'Limpieza y esterilización entre citas',
        'agenda.slot_step_minutes' => 'Cada cuánto se ofrecen horarios',
        'agenda.min_useful_gap_minutes' => 'Hueco mínimo que sirve para una cita',
        'booking.min_lead_minutes' => 'Anticipación mínima para reservar',
        'booking.horizon_days' => 'Hasta cuántos días adelante se puede reservar',
        'booking.max_web_appointments_per_patient' => 'Citas web futuras por paciente',
        'booking.max_web_appointments_per_phone' => 'Citas web futuras por celular',
        'booking.self_reschedule_min_hours' => 'Cambio automático si faltan más de',
        'booking.self_cancel_min_hours' => 'El paciente puede cancelar por su cuenta hasta',
        'booking.max_reschedule_options' => 'Horarios que puede proponer el paciente',
        'reminders.day_before_at' => 'Hora del recordatorio de la cita',
        'reminders.flag_unconfirmed_at' => 'Hora en que pasa a "llamar" si no confirma',
        'reminders.installment_days_before' => 'Días antes para el aviso de una cuota',
        'plans.valid_days' => 'Un presupuesto vale por',
        'reports.doctors_see_own' => 'Cada doctor ve sus propios ingresos y gráficos',
        'reports.monthly_goal' => 'Meta de cobro del mes',
        'web.headline' => 'Título del inicio de la web',
        'web.intro' => 'Frase bajo el título de la web',
        'web.about' => 'Sobre el consultorio',
        'web.hero_image' => 'Foto del inicio de la web',
        'web.logo' => 'Logo',
        'clinic.maps_url' => 'Enlace de Google Maps',
        'clinic.reviews_url' => 'Enlace a sus opiniones en Google',
        'clinic.google_rating' => 'Puntaje en Google',
        'clinic.google_reviews_count' => 'Cantidad de opiniones en Google',
        'clinic.instagram' => 'Instagram',
        'clinic.facebook' => 'Facebook',
        'clinic.tiktok' => 'TikTok',
        'clinic.hours_note' => 'Nota sobre el horario',
    ];

    /** @var array<class-string, array<string, string>> Cada dato con el nombre que tiene en su ficha. */
    public const FIELDS = [
        User::class => [
            'name' => 'Nombre',
            'email' => 'Correo',
            'role' => 'Rol',
            'is_dentist' => 'Es odontólogo',
            'phone' => 'Celular',
            'is_active' => 'Puede entrar al panel',
            'must_change_password' => 'Tiene que elegir su contraseña al entrar',
        ],
        Doctor::class => [
            'user_id' => 'Usuario del panel',
            'name' => 'Nombre',
            'slug' => 'Dirección en la web',
            'specialty' => 'Especialidad',
            'cop_number' => 'COP',
            'rne_number' => 'RNE',
            'bio' => 'Presentación para la web',
            'education' => 'Formación',
            'photo_path' => 'Foto para la web',
            'agenda_color' => 'Color en la agenda',
            'is_public' => 'Sale en la página web',
            'accepts_online_booking' => 'Recibe reservas en línea',
            'sort_order' => 'Orden en las listas',
            'is_active' => 'Activo',
        ],
        DoctorSchedule::class => [
            'weekday' => 'Día',
            'starts_at' => 'Desde',
            'ends_at' => 'Hasta',
            'valid_from' => 'Vale desde',
            'valid_until' => 'Vale hasta',
        ],
        Treatment::class => [
            'name' => 'Nombre',
            'slug' => 'Dirección en la web',
            'kind' => 'Tipo de tratamiento',
            'summary' => 'Resumen',
            'description' => 'Descripción',
            'price_from' => 'Precio normal',
            'show_price' => 'Mostrar el precio en la web',
            'duration_minutes' => 'Duración de la cita',
            'buffer_minutes' => 'Limpieza y esterilización',
            'sessions_label' => 'Sesiones que suele llevar',
            'checkup_months' => 'Vuelve a control cada',
            'next_session_days' => 'Sesión siguiente a los',
            'before_instructions' => 'Antes de venir',
            'aftercare' => 'Cuidados después de la atención',
            'is_evaluation' => 'Es una evaluación',
            'online_bookable' => 'Se reserva en línea',
            'is_public' => 'Sale en la página web',
            'image_path' => 'Foto',
            'seo_title' => 'Título en Google',
            'seo_description' => 'Descripción en Google',
            'sort_order' => 'Orden en las listas',
            'is_active' => 'Activo',
        ],
        TreatmentPackage::class => [
            'name' => 'Nombre',
            'description' => 'Condiciones para el paciente',
            'price' => 'Precio del paquete',
            'duration_months' => 'Duración del tratamiento',
            'for_two' => '2x1',
            'allows_upfront' => 'Pago único',
            'upfront_discount' => 'Descuento si paga todo junto',
            'allows_installments' => 'En cuotas',
            'down_payment' => 'Cuota inicial',
            'installments' => 'Número de cuotas',
            'monthly_amount' => 'Cuota mensual',
            'is_active' => 'Se ofrece',
        ],
        ConsentTemplate::class => [
            'name' => 'Nombre',
            'treatment_id' => 'Tratamiento',
            'body' => 'Texto',
            'version' => 'Versión',
            'is_active' => 'En uso',
        ],
        MessageTemplate::class => [
            'body' => 'Texto',
            'api_template_name' => 'Nombre de la plantilla aprobada',
            'api_language' => 'Idioma',
            'is_active' => 'En uso',
        ],
    ];

    /** Datos que el sistema lleva por dentro: no son cambios que alguien hizo en una pantalla. */
    public const INTERNAL = ['id', 'key', 'doctor_id', 'created_by_user_id', 'last_login_at', 'email_verified_at', 'deleted_at'];

    private const YES_NO = ['is_dentist', 'is_active', 'must_change_password', 'is_public', 'accepts_online_booking', 'show_price', 'is_evaluation', 'online_bookable', 'for_two', 'allows_upfront', 'allows_installments', 'reports.doctors_see_own'];

    private const MONEY = ['price_from', 'price', 'upfront_discount', 'down_payment', 'monthly_amount', 'reports.monthly_goal'];

    private const HOURS = ['starts_at', 'ends_at', 'reminders.day_before_at', 'reminders.flag_unconfirmed_at'];

    /** @var array<string, string> En qué viene cada número. */
    private const UNITS = [
        'duration_minutes' => 'min',
        'buffer_minutes' => 'min',
        'agenda.buffer_minutes' => 'min',
        'agenda.slot_step_minutes' => 'min',
        'agenda.min_useful_gap_minutes' => 'min',
        'checkup_months' => 'meses',
        'duration_months' => 'meses',
        'next_session_days' => 'días',
        'booking.horizon_days' => 'días',
        'plans.valid_days' => 'días',
        'reminders.installment_days_before' => 'días',
        'booking.self_reschedule_min_hours' => 'horas',
        'booking.self_cancel_min_hours' => 'horas',
    ];

    private const DATES = ['valid_from', 'valid_until'];

    /** Textos largos y archivos: en la lista se dice que cambiaron, sin copiarlos enteros. */
    private const LONG = ['body', 'bio', 'education', 'description', 'summary', 'before_instructions', 'aftercare', 'seo_description', 'web.about', 'web.intro'];

    private const FILES = ['photo_path', 'image_path', 'web.logo', 'web.hero_image'];

    /** La sección de Configuración donde se hizo el cambio. */
    public static function section(AuditLog $log): string
    {
        return self::SECTIONS[$log->auditable_type] ?? 'Otros';
    }

    /**
     * Las clases que se guardan bajo una sección ("Doctores" junta la ficha y el horario).
     *
     * @return list<class-string>
     */
    public static function classesOf(string $section): array
    {
        return array_keys(self::SECTIONS, $section, true);
    }

    /** Sobre qué fue: el nombre del doctor, del tratamiento o del ajuste. */
    public static function subject(AuditLog $log): string
    {
        $record = $log->auditable;
        $values = ($log->new_values ?? []) + ($log->old_values ?? []);

        if ($log->auditable_type === Setting::class) {
            $key = (string) ($record?->key ?? $values['key'] ?? '');

            return self::SETTINGS[$key] ?? ($key !== '' ? $key : 'Un ajuste');
        }

        if ($log->auditable_type === DoctorSchedule::class) {
            // De un tramo que ya se quitó quedan su doctor y su día en el registro de cuando se creó o se borró.
            $values += $record?->only(['doctor_id', 'weekday']) ?? [];
            $values += isset($values['doctor_id'], $values['weekday']) ? [] : self::factsOfRemovedRange($log);
            $doctor = Doctor::withTrashed()->find($values['doctor_id'] ?? null);

            return ($doctor?->name ?? 'Un doctor').' · horario del '.mb_strtolower(WeeklySchedule::DAYS[(int) ($values['weekday'] ?? 0)] ?? 'día');
        }

        if ($log->auditable_type === MessageTemplate::class) {
            $key = $record?->key ?? MessageTemplateKey::tryFrom((string) ($values['key'] ?? ''));

            return $key?->getLabel() ?? (string) ($record?->name ?? $values['name'] ?? 'Un texto de aviso');
        }

        return (string) ($record?->name ?? $values['name'] ?? 'Ya no existe');
    }

    /** El cambio en una línea, para la lista. */
    public static function summary(AuditLog $log): string
    {
        $schedule = $log->auditable_type === DoctorSchedule::class;

        return match ($log->event) {
            'created' => $schedule ? 'Se agregó el tramo '.self::range($log->new_values ?? []) : 'Se creó',
            'deleted' => $schedule ? 'Se quitó el tramo '.self::range($log->old_values ?? []) : 'Se borró',
            'restored' => 'Se restauró',
            'password_changed' => 'Se cambió su contraseña',
            default => self::changesInWords($log),
        };
    }

    /** "Duración de la cita: 30 min → 45 min · Activo: Sí → No". */
    private static function changesInWords(AuditLog $log): string
    {
        $isSetting = $log->auditable_type === Setting::class;

        return collect(self::changes($log))
            ->map(function (array $change) use ($isSetting): string {
                $words = $change['long'] ? 'se cambió el texto' : $change['before'].' → '.$change['after'];

                // De un ajuste, el dato es el ajuste mismo y ya va en "Qué": basta el antes y el después.
                return $isSetting ? Str::ucfirst($words) : $change['field'].': '.$words;
            })
            ->implode(' · ') ?: 'Se guardó sin cambios que mostrar';
    }

    /**
     * Cada dato que cambió, con lo que decía antes y lo que dice ahora.
     *
     * @return list<array{field: string, before: string, after: string, long: bool}>
     */
    public static function changes(AuditLog $log): array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];
        $setting = $log->auditable_type === Setting::class;
        $settingKey = $setting ? (string) ($log->auditable?->key ?? '') : null;
        $changes = [];

        // En el orden de la ficha, no en el que se guardaron.
        $order = array_flip(array_keys(self::FIELDS[$log->auditable_type] ?? []));
        $attributes = collect([...array_keys($old), ...array_keys($new)])->unique()->sortBy(fn (string $attribute) => $order[$attribute] ?? PHP_INT_MAX)->values();

        foreach ($attributes as $attribute) {
            if (in_array($attribute, self::INTERNAL, true)) {
                continue;
            }

            // De un ajuste se guarda solo su valor: el dato es el ajuste mismo.
            $name = $setting ? (string) $settingKey : $attribute;
            $long = in_array($name, self::LONG, true);

            $changes[] = [
                'field' => $setting ? (self::SETTINGS[$name] ?? $name) : (self::FIELDS[$log->auditable_type][$attribute] ?? Str::headline($attribute)),
                'before' => self::value($name, $old[$attribute] ?? null, $long),
                'after' => self::value($name, $new[$attribute] ?? null, $long),
                'long' => $long,
            ];
        }

        return $changes;
    }

    /**
     * El doctor y el día de un tramo del horario que ya no existe.
     *
     * @return array<string, mixed>
     */
    private static function factsOfRemovedRange(AuditLog $log): array
    {
        $sibling = AuditLog::query()
            ->where('auditable_type', $log->auditable_type)
            ->where('auditable_id', $log->auditable_id)
            ->whereIn('event', ['created', 'deleted'])
            ->first();

        return array_intersect_key(($sibling?->new_values ?? []) + ($sibling?->old_values ?? []), ['doctor_id' => true, 'weekday' => true]);
    }

    /**
     * "09:00–13:00", de lo que se guardó de un tramo del horario.
     *
     * @param  array<string, mixed>  $values
     */
    private static function range(array $values): string
    {
        return self::clock($values['starts_at'] ?? null).'–'.self::clock($values['ends_at'] ?? null);
    }

    /** Un valor como se lee en pantalla: "Sí", "S/ 120.00", "09:00", "45 min", "Recepción". */
    private static function value(string $name, mixed $value, bool $long): string
    {
        if (in_array($name, self::YES_NO, true)) {
            return $value === null ? 'Sin dato' : ((bool) $value ? 'Sí' : 'No');
        }

        if (blank($value)) {
            return in_array($name, self::FILES, true) ? 'Sin foto' : 'Vacío';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return match (true) {
            in_array($name, self::FILES, true) => 'Una foto',
            in_array($name, self::MONEY, true) => Money::format($value),
            in_array($name, self::HOURS, true) => self::clock($value),
            in_array($name, self::DATES, true) => self::date($value),
            $name === 'booking.min_lead_minutes' => Settings::LEAD_TIMES[(int) $value] ?? "{$value} minutos antes",
            isset(self::UNITS[$name]) => $value.' '.self::UNITS[$name],
            $name === 'weekday' => WeeklySchedule::DAYS[(int) $value] ?? (string) $value,
            $name === 'role' => UserRole::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            $name === 'kind' => TreatmentKind::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            $name === 'user_id' => User::query()->find($value)?->name ?? 'Un usuario que ya no existe',
            $name === 'treatment_id' => Treatment::withTrashed()->find($value)?->name ?? 'Un tratamiento que ya no existe',
            default => $long ? (string) $value : Str::limit((string) $value, 80),
        };
    }

    /** "09:00", venga como "09:00", "09:00:00" o con la fecha delante. */
    private static function clock(mixed $time): string
    {
        return preg_match('/(\d{1,2}):(\d{2})(:\d{2})?$/', trim((string) $time), $found)
            ? sprintf('%02d:%02d', $found[1], $found[2])
            : (string) $time;
    }

    private static function date(mixed $date): string
    {
        try {
            return CarbonImmutable::parse((string) $date)->format('d/m/Y');
        } catch (Throwable) {
            return (string) $date;
        }
    }
}
