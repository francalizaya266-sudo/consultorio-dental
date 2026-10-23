<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BEFORE = "Hola {nombre} 👋, te escribimos de {consultorio}.\nTu cita de {tratamiento} quedó reservada para el {fecha} a las {hora} con {doctor}.\n\nConfirma tu asistencia, cambia el horario o cancela aquí:\n{enlace}";

    private const AFTER = "Hola {nombre} 👋, te escribimos de {consultorio}.\nTu cita de {tratamiento} quedó reservada para el {fecha} a las {hora} con {doctor}.\n{precio}\n\nConfirma tu asistencia, cambia el horario o cancela aquí:\n{enlace}";

    public function up(): void
    {
        // La confirmación de la reserva web lleva el precio (con la campaña). Si el texto ya se cambió en el panel, se respeta.
        DB::table('message_templates')->where('key', 'booking_confirmation')->where('body', self::BEFORE)->update(['body' => self::AFTER]);
    }

    public function down(): void
    {
        DB::table('message_templates')->where('key', 'booking_confirmation')->where('body', self::AFTER)->update(['body' => self::BEFORE]);
    }
};
