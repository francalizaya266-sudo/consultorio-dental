<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lo que se hizo en la atención fuera del plan (lo programado en la cita y lo que se hizo de más):
        // de aquí salen el cobro y los materiales, no de lo que estaba programado.
        Schema::create('consultation_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['consultation_id', 'treatment_id'], 'consultation_treatments_unique');
        });

        // Las atenciones que ya existen quedan con lo que el sistema daba por hecho: el tratamiento de la cita,
        // salvo que la cita fuera una sesión del plan o que ese tratamiento se marcara del plan. Así su cobro no cambia.
        DB::table('consultation_treatments')->insertUsing(
            ['consultation_id', 'treatment_id', 'created_at', 'updated_at'],
            DB::table('consultations')
                ->join('appointments', 'appointments.id', '=', 'consultations.appointment_id')
                ->whereNull('appointments.treatment_plan_item_id')
                ->whereNotNull('appointments.treatment_id')
                ->whereNotExists(fn (Builder $worked) => $worked
                    ->selectRaw('1')
                    ->from('consultation_plan_items')
                    ->join('treatment_plan_items', 'treatment_plan_items.id', '=', 'consultation_plan_items.treatment_plan_item_id')
                    ->whereColumn('consultation_plan_items.consultation_id', 'consultations.id')
                    ->whereColumn('treatment_plan_items.treatment_id', 'appointments.treatment_id'))
                ->select(['consultations.id', 'appointments.treatment_id', 'consultations.created_at', 'consultations.updated_at']),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_treatments');
    }
};
