<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El aviso para quien faltó o canceló y sigue sin otra cita. */
    public function up(): void
    {
        DB::table('message_templates')->insertOrIgnore([
            'key' => MessageTemplateKey::MissedVisit->value,
            'name' => MessageTemplateKey::MissedVisit->getLabel(),
            'body' => MessageTemplateKey::MissedVisit->defaultBody(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('message_templates')->where('key', MessageTemplateKey::MissedVisit->value)->delete();
    }
};
