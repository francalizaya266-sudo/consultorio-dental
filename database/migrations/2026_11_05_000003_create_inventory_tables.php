<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A quién se le compra: el pedido sale por su WhatsApp.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Cada material, con lo que hay (la suma de sus movimientos) y desde cuánto hay que pedir.
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 30);
            $table->string('unit', 30)->default('unidad');
            $table->decimal('stock', 10, 2)->default(0);
            $table->decimal('min_stock', 10, 2)->default(0);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            // Implantes: cada unidad tiene su número de serie y queda registrada en el paciente.
            $table->boolean('tracks_serial')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->string('status', 20)->default('sent');
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained();
            $table->decimal('quantity', 10, 2);
            $table->decimal('received_quantity', 10, 2)->nullable();
            $table->timestamps();
        });

        // Cada lote que entró, con su vencimiento y lo que queda. Sale primero lo que vence antes.
        Schema::create('supply_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->string('lot', 60)->nullable();
            $table->string('serial', 80)->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('quantity', 10, 2);
            $table->decimal('remaining', 10, 2);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->timestamp('received_at');
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['supply_id', 'remaining']);
            $table->index('expires_on');
        });

        // La receta: qué y cuánto usa cada tratamiento en una atención.
        Schema::create('treatment_supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 8, 2);
            $table->timestamps();

            $table->unique(['treatment_id', 'supply_id']);
        });

        // Cada entrada, uso, devolución, ajuste o baja, con quién lo hizo y por qué. Nada se borra.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supply_id')->constrained();
            $table->foreignId('supply_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Un uso que se devolvió al reabrir la atención.
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['supply_id', 'created_at']);
            $table->index(['consultation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('treatment_supplies');
        Schema::dropIfExists('supply_batches');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('supplies');
        Schema::dropIfExists('suppliers');
    }
};
