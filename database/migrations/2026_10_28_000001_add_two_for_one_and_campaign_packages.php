<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 2x1: con el mismo pago se atiende otra persona.
        Schema::table('treatment_packages', function (Blueprint $table) {
            $table->boolean('for_two')->default(false)->after('price');
        });

        // El plan de la otra persona del 2x1 apunta al plan de quien paga.
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->foreignId('included_in_plan_id')->nullable()->after('consultation_id')->constrained('treatment_plans')->nullOnDelete();
        });

        // Una campaña puede ofrecer un paquete.
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('treatment_package_id')->nullable()->after('treatment_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treatment_package_id');
        });

        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('included_in_plan_id');
        });

        Schema::table('treatment_packages', function (Blueprint $table) {
            $table->dropColumn('for_two');
        });
    }
};
