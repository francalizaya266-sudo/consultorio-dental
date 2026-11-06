<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Los datos propios del tratamiento en la evolución: {"tooth": "36", "surfaces": "MO", "shade": "A2"}.
        Schema::table('consultations', function (Blueprint $table) {
            $table->json('procedure_details')->nullable()->after('procedures');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn('procedure_details');
        });
    }
};
