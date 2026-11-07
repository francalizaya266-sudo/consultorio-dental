<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El horario que se le ofrece a alguien de la lista de espera queda apartado un rato: nadie más lo toma
     * mientras responde. Se guarda hasta cuándo ocupa la agenda y hasta cuándo dura el apartado.
     */
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->timestamp('offered_until')->nullable()->after('offered_starts_at');
            $table->timestamp('held_until')->nullable()->after('offered_doctor_id');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropColumn(['offered_until', 'held_until']);
        });
    }
};
