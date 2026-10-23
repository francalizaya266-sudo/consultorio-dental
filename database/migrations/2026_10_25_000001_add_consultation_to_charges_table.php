<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lo cargado solo al terminar una atención: así no se carga dos veces si se reabre.
        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('consultation_id')->nullable()->after('appointment_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultation_id');
        });
    }
};
