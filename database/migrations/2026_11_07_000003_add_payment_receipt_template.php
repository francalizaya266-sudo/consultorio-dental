<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** La constancia de pago por WhatsApp, editable en el panel como los demás avisos. */
    public function up(): void
    {
        DB::table('message_templates')->insertOrIgnore([
            'key' => MessageTemplateKey::PaymentReceipt->value,
            'name' => MessageTemplateKey::PaymentReceipt->getLabel(),
            'body' => MessageTemplateKey::PaymentReceipt->defaultBody(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('message_templates')->where('key', MessageTemplateKey::PaymentReceipt->value)->delete();
    }
};
