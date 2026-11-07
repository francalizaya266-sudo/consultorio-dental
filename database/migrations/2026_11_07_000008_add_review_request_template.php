<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El pedido de opinión en Google, editable en el panel como los demás avisos. */
    public function up(): void
    {
        DB::table('message_templates')->insertOrIgnore([
            'key' => MessageTemplateKey::ReviewRequest->value,
            'name' => MessageTemplateKey::ReviewRequest->getLabel(),
            'body' => MessageTemplateKey::ReviewRequest->defaultBody(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('message_templates')->where('key', MessageTemplateKey::ReviewRequest->value)->delete();
    }
};
