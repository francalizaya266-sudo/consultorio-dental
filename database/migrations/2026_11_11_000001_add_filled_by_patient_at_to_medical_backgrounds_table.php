<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Cuándo los llenó el propio paciente desde Mi cuenta: el doctor los confirma en la consulta y se borra. */
    public function up(): void
    {
        Schema::table('medical_backgrounds', function (Blueprint $table) {
            $table->timestamp('filled_by_patient_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('medical_backgrounds', function (Blueprint $table) {
            $table->dropColumn('filled_by_patient_at');
        });
    }
};
