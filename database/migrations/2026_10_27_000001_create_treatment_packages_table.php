<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paquete que arma el doctor: varios tratamientos a un precio, y cómo se puede pagar.
        Schema::create('treatment_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->boolean('allows_upfront')->default(true);
            $table->decimal('upfront_discount', 10, 2)->default(0);
            $table->boolean('allows_installments')->default(false);
            $table->decimal('down_payment', 10, 2)->default(0);
            $table->unsignedTinyInteger('installments')->nullable();
            // Si el doctor fijó la cuota mensual; si no, el resto se reparte en partes iguales.
            $table->decimal('monthly_amount', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('treatment_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained();
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('sessions_planned')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // De qué paquete vino cada procedimiento del plan.
        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->foreignId('treatment_package_id')->nullable()->after('treatment_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_plan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treatment_package_id');
        });

        Schema::dropIfExists('treatment_package_items');
        Schema::dropIfExists('treatment_packages');
    }
};
