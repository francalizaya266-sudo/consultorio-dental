# Sistema web para consultorio dental

Reservas online verificadas por WhatsApp, agenda inteligente, historia clínica con odontograma y paneles para administrador, recepción y doctores. Pagos solo informativos (se cobra en el consultorio).

**Stack:** Laravel 13 (PHP 8.3+) · MySQL · Filament 5 para los paneles · Blade + Livewire para la web pública. Pensado para hosting compartido con PHP: sin Node en producción y con un solo cron.

## Puesta en marcha local

```bash
composer install
cp .env.example .env
php artisan key:generate
# crear la base de datos MySQL y completar DB_* en .env
php artisan migrate --seed
php artisan serve
```

En local, `--seed` crea datos de demostración. Panel: `/panel` con `admin@consultorio.test`, `recepcion@consultorio.test`, `dra.torres@consultorio.test` o `dr.ramos@consultorio.test` (clave `password`).

Pruebas: `php artisan test` (usan SQLite en memoria; también pasan sobre MySQL).

## Publicación en hosting compartido

1. La raíz pública del dominio apunta a la carpeta `public/`.
2. `.env` con `APP_ENV=production`, `APP_DEBUG=false`, los datos de MySQL y `APP_URL` con `https://`.
3. `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force` y `php artisan db:seed --class=CatalogSeeder --force` (solo catálogos, sin datos de prueba).
4. Un único cron cada minuto:
   `* * * * * php /ruta/al/proyecto/artisan schedule:run >> /dev/null 2>&1`
   Ese cron prepara los recordatorios del día anterior, marca las citas sin confirmar, procesa la cola y limpia enlaces vencidos.
5. PHP con la extensión **GD** (casi todos los hostings la traen): comprime radiografías y fotos al subirlas. Esos archivos y las firmas quedan en `storage/app/private/clinica`, fuera de `public/`; inclúyanlo en las copias de seguridad junto con la base de datos.

## Cómo está organizado

| Carpeta | Qué hay |
| --- | --- |
| `app/Models` | Modelos. Los datos clínicos, citas y pagos registran cada cambio (`Concerns/Auditable`) y no se borran. |
| `app/Enums` | Estados y opciones con su texto en español (estado de la cita, medio de pago, parentesco…). |
| `app/Services/Agenda` | Agenda inteligente: horarios donde cabe la duración completa más la esterilización, con prioridad a los pegados a otras citas. |
| `app/Services/Booking` | Reservas, confirmación, cambios de horario en dos niveles y cancelaciones. Toda escritura en la agenda pasa por aquí. |
| `app/Services/Access` | Enlaces personales por WhatsApp y dispositivos recordados (sin contraseñas ni códigos). |
| `app/Services/WhatsApp` | Bandeja de avisos: hoy se envían con un clic desde el WhatsApp del consultorio; mañana, por la API oficial sin cambiar nada más. |
| `app/Services/Clinical` | Historia clínica: atención, odontograma, plan de tratamiento, recetas, radiografías y fotos (comprimidas) y consentimientos firmados en pantalla. |
| `config/consultorio.php` | Valores por defecto (plazos, topes, tiempos). Se pueden cambiar desde el panel (tabla `settings`). |

## Reglas del negocio que implementa

- **Familias:** un celular (`accounts`) puede tener varios pacientes a cargo; cada uno con su propia historia y odontograma.
- **Reserva web al instante** si el horario está libre. Queda *pendiente* hasta que el paciente confirma con su enlace; el día anterior, si no confirmó, se marca *sin confirmar* para que recepción llame.
- **Topes:** 2 citas web futuras por paciente y un tope por celular (configurable). Recepción puede bloquear números.
- **Primera cita** en la web (evaluación o tratamiento simple). Las siguientes las programa el doctor; un paciente con plan en curso no reserva sesiones solo.
- **Cambios de horario:** con más de 24 h, mismo doctor y tratamiento, el cambio es automático; si no (o la sesión tiene *fecha fija*), el paciente propone hasta 3 horarios y recepción o el doctor aprueba uno.
- **Cancelación** directa hasta el plazo que define el consultorio; después, debe comunicarse.
- **Doctores** solo manejan su propia agenda; cada cambio prepara el aviso al paciente y alerta a recepción.
- **Datos clínicos** solo los ven los doctores y el administrador si es odontólogo (Ley N.º 29733).

## WhatsApp: etapa 1 y etapa 2

- **Etapa 1 (`WHATSAPP_DRIVER=click_to_chat`):** el panel arma el mensaje con un enlace personal nuevo y abre WhatsApp ya escrito; recepción solo presiona Enviar. Sin programas no oficiales.
- **Etapa 2 (`WHATSAPP_DRIVER=cloud_api`):** con el trámite de Meta listo, se completan `WHATSAPP_CLOUD_TOKEN` y `WHATSAPP_PHONE_NUMBER_ID`, y en cada texto del panel el nombre de su plantilla aprobada. `whatsapp:enviar` los manda solos cada 5 minutos.
