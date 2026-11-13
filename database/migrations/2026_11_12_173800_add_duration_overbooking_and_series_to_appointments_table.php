<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tres datos nuevos de la cita: si su duración se puso a mano (al moverla no se vuelve a calcular), si es un
     * sobrecupo (se agendó a propósito encima de otra) y a qué serie pertenece cuando se agendó "cada tantas semanas".
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('custom_duration')->default(false)->after('buffer_minutes');
            $table->boolean('is_overbooked')->default(false)->after('fixed_date');
            $table->uuid('series_id')->nullable()->after('treatment_plan_item_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['series_id']);
            $table->dropColumn(['custom_duration', 'is_overbooked', 'series_id']);
        });
    }
};
