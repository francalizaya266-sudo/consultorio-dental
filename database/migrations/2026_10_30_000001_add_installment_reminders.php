<?php

use App\Enums\MessageTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuándo se preparó el aviso de la cuota, para no avisar dos veces.
        Schema::table('treatment_plan_installments', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('charge_id');
        });

        // Los textos nuevos, para poder editarlos en el panel como los demás avisos.
        foreach ([MessageTemplateKey::InstallmentReminder, MessageTemplateKey::InstallmentOverdue] as $key) {
            DB::table('message_templates')->insertOrIgnore([
                'key' => $key->value,
                'name' => $key->getLabel(),
                'body' => $key->defaultBody(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('message_templates')->whereIn('key', [MessageTemplateKey::InstallmentReminder->value, MessageTemplateKey::InstallmentOverdue->value])->delete();

        Schema::table('treatment_plan_installments', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
