<?php

use App\Enums\TreatmentKind;
use App\Models\Treatment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada tratamiento guarda sus reglas: cada cuánto vuelve a control, a cuántos días va la sesión siguiente,
     * qué decirle al paciente antes de venir y cómo cuidarse después. Los de hoy quedan con lo que el sistema
     * ya venía usando según su nombre (ortodoncia al mes, implante al año, lo demás a los 6 meses).
     */
    public function up(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->unsignedTinyInteger('checkup_months')->nullable()->after('sessions_label');
            $table->unsignedSmallInteger('next_session_days')->nullable()->after('checkup_months');
            $table->string('before_instructions', 300)->nullable()->after('next_session_days');
            $table->text('aftercare')->nullable()->after('before_instructions');
        });

        Treatment::withTrashed()->get(['id', 'name', 'is_evaluation'])->each(function (Treatment $treatment) {
            $kind = TreatmentKind::for($treatment);

            DB::table('treatments')->where('id', $treatment->getKey())->update([
                'checkup_months' => $kind->checkupMonths(),
                'next_session_days' => $kind->nextSessionDays(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->dropColumn(['checkup_months', 'next_session_days', 'before_instructions', 'aftercare']);
        });
    }
};
