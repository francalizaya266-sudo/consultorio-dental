<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El texto anterior, sin decir hasta cuándo vale el presupuesto. */
    private const OLD_BODY = "Hola {nombre}, te enviamos tu plan de tratamiento con {doctor}:\n\n{plan}\n\nTotal: {total}\nEs un presupuesto referencial: si en el camino el tratamiento cambia, te lo explicamos antes. Cualquier duda, escríbenos.";

    /** El presupuesto por WhatsApp dice hasta cuándo vale. Si el consultorio ya cambió el texto, se respeta. */
    public function up(): void
    {
        DB::table('message_templates')
            ->where('key', MessageTemplateKey::TreatmentPlan->value)
            ->where('body', self::OLD_BODY)
            ->update(['body' => MessageTemplateKey::TreatmentPlan->defaultBody(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('message_templates')
            ->where('key', MessageTemplateKey::TreatmentPlan->value)
            ->where('body', MessageTemplateKey::TreatmentPlan->defaultBody())
            ->update(['body' => self::OLD_BODY, 'updated_at' => now()]);
    }
};
