<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El horario que se le ofreció por WhatsApp: con él se agenda si dice que sí.
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->timestamp('offered_starts_at')->nullable()->after('offered_at');
            $table->foreignId('offered_doctor_id')->nullable()->after('offered_starts_at')->constrained('doctors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offered_doctor_id');
            $table->dropColumn('offered_starts_at');
        });
    }
};
