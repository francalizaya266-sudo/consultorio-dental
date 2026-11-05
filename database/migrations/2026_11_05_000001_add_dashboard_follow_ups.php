<?php

use App\Enums\MessageTemplateKey;
use App\Enums\TreatmentPlanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Avisos para el seguimiento que se hace desde "Hoy". */
    private const TEMPLATES = [
        MessageTemplateKey::PlanFollowUp,
        MessageTemplateKey::PlanNextSession,
        MessageTemplateKey::CheckupRecall,
        MessageTemplateKey::Birthday,
    ];

    public function up(): void
    {
        // Desde cuándo el presupuesto espera respuesta del paciente.
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->timestamp('proposed_at')->nullable()->after('status');
            $table->index(['status', 'proposed_at']);
        });

        // Los ya presentados toman la fecha de su último cambio, la mejor aproximación que hay.
        DB::table('treatment_plans')
            ->where('status', TreatmentPlanStatus::Proposed->value)
            ->update(['proposed_at' => DB::raw('updated_at')]);

        foreach (self::TEMPLATES as $key) {
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
        DB::table('message_templates')->whereIn('key', array_map(fn (MessageTemplateKey $key) => $key->value, self::TEMPLATES))->delete();

        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->dropIndex(['status', 'proposed_at']);
            $table->dropColumn('proposed_at');
        });
    }
};
