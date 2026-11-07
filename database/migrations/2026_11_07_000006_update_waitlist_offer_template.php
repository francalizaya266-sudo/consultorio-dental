<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El texto anterior, sin decir hasta cuándo se guarda el horario. */
    private const OLD_BODY = 'Hola {nombre}, se liberó un horario para {tratamiento} el {fecha} a las {hora} con {doctor}. ¿Lo quieres? Respóndenos y te lo reservamos.';

    /** El horario ofrecido queda apartado un rato: el mensaje dice hasta cuándo. Si el consultorio ya cambió el texto, se respeta. */
    public function up(): void
    {
        DB::table('message_templates')
            ->where('key', MessageTemplateKey::WaitlistOffer->value)
            ->where('body', self::OLD_BODY)
            ->update(['body' => MessageTemplateKey::WaitlistOffer->defaultBody(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('message_templates')
            ->where('key', MessageTemplateKey::WaitlistOffer->value)
            ->where('body', MessageTemplateKey::WaitlistOffer->defaultBody())
            ->update(['body' => self::OLD_BODY, 'updated_at' => now()]);
    }
};
