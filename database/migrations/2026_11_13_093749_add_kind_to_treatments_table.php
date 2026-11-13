<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El tipo de tratamiento (limpieza, endodoncia, implante…) elegido en su ficha. Vacío = como hasta ahora:
     * el sistema lo deduce del nombre.
     */
    public function up(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
