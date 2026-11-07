<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El texto anterior, con los 6 meses fijos. */
    private const OLD_BODY = "Hola {nombre}, te escribimos de {consultorio}.\nYa pasaron más de 6 meses desde tu última visita: es buen momento para tu control y limpieza. ¿Te reservamos una cita?";

    /** El llamado a control dice el control que toca (ortodoncia al mes, implante al año). Si el consultorio ya cambió el texto, se respeta. */
    public function up(): void
    {
        DB::table('message_templates')
            ->where('key', MessageTemplateKey::CheckupRecall->value)
            ->where('body', self::OLD_BODY)
            ->update(['body' => MessageTemplateKey::CheckupRecall->defaultBody(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('message_templates')
            ->where('key', MessageTemplateKey::CheckupRecall->value)
            ->where('body', MessageTemplateKey::CheckupRecall->defaultBody())
            ->update(['body' => self::OLD_BODY, 'updated_at' => now()]);
    }
};
