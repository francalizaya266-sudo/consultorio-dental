<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** El apoderado de un menor: quién firma por él los consentimientos y el plan. */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('guardian_name', 150)->nullable()->after('emergency_contact_relationship');
            $table->string('guardian_document', 20)->nullable()->after('guardian_name');
            $table->string('guardian_relationship', 20)->nullable()->after('guardian_document');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['guardian_name', 'guardian_document', 'guardian_relationship']);
        });
    }
};
