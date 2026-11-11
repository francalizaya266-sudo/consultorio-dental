<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Trabajos enviados al laboratorio dental (coronas, prótesis, férulas): cuándo salieron, cuándo llegan y cuánto cuestan. */
    public function up(): void
    {
        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 200);
            $table->string('laboratory', 120);
            $table->date('sent_on');
            $table->date('due_on')->index();
            $table->date('received_on')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_orders');
    }
};
