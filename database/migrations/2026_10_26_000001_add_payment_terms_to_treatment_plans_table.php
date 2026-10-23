<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cómo paga el paciente lo que aceptó: por sesión, todo junto o en cuotas.
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->string('payment_mode', 20)->nullable()->after('status');
        });

        // Aceptado = precio firmado, ya no se edita. Los del cronograma no se cargan por sesión.
        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('status');
            $table->boolean('in_payment_schedule')->default(false)->after('accepted_at');
        });

        // Cada firma del paciente: la aceptación del plan y la de lo que se agregó después.
        Schema::create('treatment_plan_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_plan_id')->constrained()->cascadeOnDelete();
            $table->string('signer_name');
            $table->string('signer_document', 20)->nullable();
            $table->string('signer_relationship', 20);
            $table->string('signature_path');
            // Lo que se firmó, tal como estaba: procedimientos, precios y forma de pago.
            $table->text('summary');
            $table->decimal('total', 10, 2);
            $table->dateTime('signed_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Cronograma de pagos: la inicial (número 0) o el pago único, y las cuotas. Se cargan al vencer.
        Schema::create('treatment_plan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_on');
            $table->decimal('amount', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['due_on', 'charge_id']);
        });

        // Los planes que ya estaban aceptados quedan con su precio firmado.
        DB::table('treatment_plan_items')
            ->whereIn('treatment_plan_id', DB::table('treatment_plans')->whereNotNull('accepted_at')->select('id'))
            ->update(['accepted_at' => DB::raw('(select accepted_at from treatment_plans where treatment_plans.id = treatment_plan_items.treatment_plan_id)')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_plan_installments');
        Schema::dropIfExists('treatment_plan_signatures');

        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->dropColumn(['accepted_at', 'in_payment_schedule']);
        });

        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });
    }
};
