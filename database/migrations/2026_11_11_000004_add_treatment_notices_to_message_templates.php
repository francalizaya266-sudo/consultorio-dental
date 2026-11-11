<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El recordatorio anterior, igual para todos los tratamientos. */
    private const OLD_REMINDER = "Hola {nombre}, te recordamos tu cita de mañana {fecha} a las {hora} con {doctor} en {consultorio}.\n\nConfirma tu asistencia aquí:\n{enlace}";

    /**
     * El recordatorio lleva las indicaciones del tratamiento (si el consultorio ya cambió el texto, se respeta)
     * y hay un aviso nuevo con los cuidados para después de la atención.
     */
    public function up(): void
    {
        DB::table('message_templates')
            ->where('key', MessageTemplateKey::Reminder->value)
            ->where('body', self::OLD_REMINDER)
            ->update(['body' => MessageTemplateKey::Reminder->defaultBody(), 'updated_at' => now()]);

        DB::table('message_templates')->insertOrIgnore([
            'key' => MessageTemplateKey::Aftercare->value,
            'name' => MessageTemplateKey::Aftercare->getLabel(),
            'body' => MessageTemplateKey::Aftercare->defaultBody(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('message_templates')->where('key', MessageTemplateKey::Aftercare->value)->delete();

        DB::table('message_templates')
            ->where('key', MessageTemplateKey::Reminder->value)
            ->where('body', MessageTemplateKey::Reminder->defaultBody())
            ->update(['body' => self::OLD_REMINDER, 'updated_at' => now()]);
    }
};
