<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuánto dura el tratamiento de un paquete (ortodoncia: 18 meses) y, en el plan de cada paciente, cada cuántos
     * días va a control (según sus brackets: cada 15 días o cada mes).
     */
    public function up(): void
    {
        Schema::table('treatment_packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('duration_months')->nullable()->after('price');
        });

        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('session_interval_days')->nullable()->after('sessions_done');
        });
    }

    public function down(): void
    {
        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->dropColumn('session_interval_days');
        });

        Schema::table('treatment_packages', function (Blueprint $table) {
            $table->dropColumn('duration_months');
        });
    }
};
