<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qué procedimientos del plan se trabajaron en cada atención: de aquí sale el avance del plan.
        Schema::create('consultation_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_plan_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['consultation_id', 'treatment_plan_item_id'], 'consultation_plan_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_plan_items');
    }
};
