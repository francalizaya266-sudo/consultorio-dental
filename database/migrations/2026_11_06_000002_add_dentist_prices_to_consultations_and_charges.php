<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El precio que puso el dentista en lo que se hizo, cuando no es el sugerido: {"item-12": 250, "treatment-5": 180}.
        Schema::table('consultations', function (Blueprint $table) {
            $table->json('prices')->nullable()->after('next_steps');
        });

        // Lo que sugería el sistema cuando el dentista cobró otro precio. Con él, el plan sigue cuadrando:
        // la diferencia de una sesión no se cobra (ni se descuenta) en las siguientes.
        Schema::table('charges', function (Blueprint $table) {
            $table->decimal('suggested_amount', 10, 2)->nullable()->after('discount_reason');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('suggested_amount');
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn('prices');
        });
    }
};
