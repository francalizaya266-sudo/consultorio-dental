<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El aviso de una promoción a quien aceptó recibirlas, editable en el panel como los demás avisos. */
    public function up(): void
    {
        DB::table('message_templates')->insertOrIgnore([
            'key' => MessageTemplateKey::CampaignAnnouncement->value,
            'name' => MessageTemplateKey::CampaignAnnouncement->getLabel(),
            'body' => MessageTemplateKey::CampaignAnnouncement->defaultBody(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('message_templates')->where('key', MessageTemplateKey::CampaignAnnouncement->value)->delete();
    }
};
