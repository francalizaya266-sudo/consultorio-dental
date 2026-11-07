<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lo que el doctor realmente tarda en cada tratamiento, medido de sus atenciones: la agenda lo reserva. */
    public function up(): void
    {
        Schema::table('doctor_treatment', function (Blueprint $table) {
            $table->unsignedSmallInteger('learned_minutes')->nullable()->after('duration_minutes');
            $table->unsignedSmallInteger('learned_samples')->nullable()->after('learned_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_treatment', function (Blueprint $table) {
            $table->dropColumn(['learned_minutes', 'learned_samples']);
        });
    }
};
