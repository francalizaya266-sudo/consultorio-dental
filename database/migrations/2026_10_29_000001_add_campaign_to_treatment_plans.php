<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El plan que se armó con el paquete de una campaña: para sus cupos y su reporte.
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('included_in_plan_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
    }
};
