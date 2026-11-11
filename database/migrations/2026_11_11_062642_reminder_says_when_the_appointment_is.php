<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Lo que decía el recordatorio: siempre "mañana", aunque saliera el sábado para una cita del lunes. */
    private const ALWAYS_TOMORROW = 'de mañana {fecha}';

    /** Ahora lo arma el sistema al enviarlo: "de mañana lunes 16 de noviembre", "del lunes 16 de noviembre". */
    private const WHEN_IT_IS = '{cuando}';

    /** Cambia solo esa frase: si el consultorio retocó el resto del texto, se respeta. */
    public function up(): void
    {
        $this->replaceInReminder(self::ALWAYS_TOMORROW, self::WHEN_IT_IS);

        DB::table('message_templates')
            ->where('key', MessageTemplateKey::Reminder->value)
            ->where('name', 'Recordatorio del día anterior')
            ->update(['name' => MessageTemplateKey::Reminder->getLabel()]);
    }

    public function down(): void
    {
        $this->replaceInReminder(self::WHEN_IT_IS, self::ALWAYS_TOMORROW);

        DB::table('message_templates')
            ->where('key', MessageTemplateKey::Reminder->value)
            ->where('name', MessageTemplateKey::Reminder->getLabel())
            ->update(['name' => 'Recordatorio del día anterior']);
    }

    private function replaceInReminder(string $search, string $replace): void
    {
        DB::table('message_templates')
            ->where('key', MessageTemplateKey::Reminder->value)
            ->get(['id', 'body'])
            ->each(fn (object $template) => DB::table('message_templates')
                ->where('id', $template->id)
                ->update(['body' => str_replace($search, $replace, $template->body), 'updated_at' => now()]));
    }
};
